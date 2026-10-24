<?php
// Tagger: deterministic rules (domain rules, format tags, YouTube category, vocabulary keyword hits) plus ONE OpenRouter call per
// bookmark for vocabulary picks, new-tag suggestions, category and a short summary. No local ML. Falls back to the rules when the API fails.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/crawler.php';

const BM_CATEGORIES = ['dev', 'news', 'video', 'shopping', 'reference', 'article', 'tool', 'other'];
const BM_FORMAT_TAGS = ['video', 'book', 'podcast', 'recipe', 'paper', 'product', 'repo', 'article', 'reference', 'tool'];   // medium, from structure (format_rules.txt); never from the model, never counted toward the 3
const BM_MIN = 2;      // applied topic tags: 2..4 (format tags come on top)
const BM_MAX = 4;
const BM_ALIASES = ['golang' => 'go', 'js' => 'javascript', 'machine learning' => 'ai', 'llm' => 'ai', 'open source' => 'opensource', 'selfhosted' => 'self-hosted', 'homelab' => 'self-hosted',
    'recipe' => 'food', 'sqlite' => 'database', 'sql' => 'database', 'docker' => 'devops', 'kubernetes' => 'devops', 'cybersecurity' => 'security', 'nachrichten' => 'news', 'politik' => 'politics'];
const BM_YT_CATEGORY = ['music' => 'music', 'gaming' => 'gaming',
    'film & animation' => 'film', 'sports' => 'sports', 'travel & events' => 'travel', 'autos & vehicles' => 'cars', 'pets & animals' => 'animals'];

function normalize_tags(array $tags): array {
    $out = [];
    foreach ($tags as $t) {
        $t = trim(preg_replace('/[^\p{L}\p{N} +#.-]+/u', '', mb_strtolower(trim((string)$t))));
        if ($t !== '' && mb_strlen($t) <= 24) $out[$t] = true;
    }
    return array_keys($out);
}

/** Vocabulary tag => description: seed_tags.txt ("tag: description") plus the user's most-used tags. Model-applied tags only feed back when the user confirmed them ($all = false), else early guesses snowball. */
function vocab(PDO $pdo, int $uid, int $limit = 60, bool $all = false): array {
    $v = [];
    foreach (@file(cfg('seed_tags_file') ?: __DIR__ . '/../seed_tags.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        if ($l[0] === '#') continue;
        [$t, $d] = array_pad(explode(':', $l, 2), 2, '');
        if ($t = mb_strtolower(trim($t))) $v[$t] = trim($d) ?: $t;
    }
    $q = $pdo->prepare("SELECT t.tag FROM tags t JOIN bookmarks b ON b.id=t.bookmark_id WHERE b.user_id=? AND b.deleted_at IS NULL AND t.suggested=0 AND (b.tags_edited=1 OR " . (int)$all . ") GROUP BY t.tag ORDER BY COUNT(*) DESC LIMIT $limit");
    $q->execute([$uid]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) $v[$t] ??= $t;
    return $v;
}

/** Vocabulary offered to the user for autocomplete: seed tags + their own tags. */
function user_vocabulary(int $uid): array { return array_keys(vocab(db(), $uid, 200, true)); }


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
    if (mb_strlen($s) > 450) {   // cut at the last sentence end that fits
        $cut = mb_substr($s, 0, 450);
        $s = preg_match('/^.*[.!?]/su', $cut, $m) ? $m[0] : '';
    }
    $s = preg_replace('/^(this|the) (web)?(page|video|article|post|site|website|book|podcast|channel) (\w+s|\w+ed) (that|how) /iu', '', $s, 1, $n);   // "This article argues that X" -> "X"; other openers are rejected and retried
    if ($n) $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    return (mb_strlen($s) < 20 || preg_match('/^(this|the) (web)?(page|video|article|post|site|website|book|podcast|channel)\b|^diese seite\b|\b(available|kaufen|disponibile|formats?|price|shipping|in stock|add to cart)\b/i', $s)) ? '' : $s;
}

/** Readable title from the URL's last meaningful path segment ("radar-dome-belt.html" -> "Radar dome belt"); the host when there is none. */
function title_from_url(string $url): string {
    $segs = array_reverse(array_filter(explode('/', urldecode((string)parse_url($url, PHP_URL_PATH)))));
    foreach ($segs as $seg) {   // skip index pages and bare IDs
        $t = trim(preg_replace('/[\s_+\-]+/', ' ', preg_replace('/\.(html?|php|aspx?)$/i', '', $seg)));
        if (preg_match('/\p{L}{3}/u', $t) && mb_strlen($t) <= 90 && !preg_match('/^(index|default|home|main)$/i', $t) && !(preg_match('/\d/', $t) && !str_contains($t, ' '))) return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }
    return host_of($url);
}

/** A model-written replacement title: 3-90 chars, no markup; '' = keep the crawled one. */
function valid_title(string $t): string {
    $t = clean_text(strip_tags($t));
    return (mb_strlen($t) < 3 || mb_strlen($t) > 90) ? '' : $t;
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
    $st = $pdo->prepare('SELECT id, title, url FROM bookmarks WHERE user_id=? AND deleted_at IS NULL AND tags_edited=1 ORDER BY id DESC LIMIT 60');
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

/** Count an OpenRouter call: model '' = every call made (the monthly cap), other rows = the model of the fallback list that answered. */
function count_api_call(PDO $pdo, string $month, string $model): void {
    $pdo->prepare('INSERT INTO api_usage(month,model,calls) VALUES(?,?,1) ON CONFLICT(month,model) DO UPDATE SET calls=calls+1')->execute([$month, $model]);
}

/** Transport: monthly cap + counters, `models` fallback list. @return array{j:array,cost:float}|array{transient:true}|array{} */
function openrouter_post(PDO $pdo, string $prompt, int $maxTokens): array {
    if (!($key = cfg('openrouter_key'))) return [];
    $month = date('Y-m');
    $n = $pdo->prepare("SELECT calls FROM api_usage WHERE month=? AND model=''"); $n->execute([$month]);
    if ((int)$n->fetchColumn() >= (int)(cfg('openrouter_cap') ?: 3000)) { error_log('openrouter: monthly call cap reached'); return []; }
    count_api_call($pdo, $month, '');
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
        count_api_call($pdo, $month, $r['model']);
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
    $ask = fn(string $extra) => $base . "\n\nReply with JSON only: {\"tags\": [up to 4 tags chosen ONLY from the vocabulary, most relevant first; fewer (even 1) is fine when nothing else clearly fits; only tags central to this page; avoid vague catch-alls like tools, tech, business, productivity, news, art, science, docs, devops, opensource or fun unless the page is really about them; never tag something unrelated such as cars for a boat], "
        . "\"new_tags\": [0 to 2 new lowercase tags, preferably ONE general word (boat, not boat design), only ones that clearly and centrally describe this page; an empty list is the normal answer when the vocabulary tags already cover it], \"category\": \"one of " . implode('|', BM_CATEGORIES) . "\", "
        . "\"title\": \"empty string unless the page title is bad (generic like Home or Untitled, only the site name, a bot-check or error page, or clearly unrelated to the content); then a short descriptive title of at most 70 characters in the page's language, never just the site name\", "
        . "\"summary\": \"Write 2-3 sentences (max 400 characters) on what this is about and what it offers or argues. Start with the substance, not with the medium: never say 'A YouTube video', 'A Wikipedia article', 'A web page', 'A website' or 'This article', since the site already shows that. "
        . "Lead with the topic or the main claim, not with who made it. Mention the author or channel only when they are the point (a famous author, an essay, a book) and then at most once, never as the sentence subject by default. Never write 'the video', 'the article' or 'the page' anywhere in the summary. "
        . "Name the form only when it adds information (an essay, a paper, a tutorial, a review, a novel). Examples: 'How a 10-foot stitch-and-glue dinghy rows, tows and sails, and how it was designed.', 'Federalist No. 10 (Madison) argues that a large republic best contains the danger of factions.', 'Speed-modelling a Scandinavian medieval house in Blender for game use.', 'The 1788 London panic over an unidentified attacker of women.' "
        . "Describe only the actual content: never list or mention links, Patreon, Discord, newsletters, courses, sponsors, subscribe or follow requests, or other promotion. Always write full sentences with a verb; skip spec sheets (dimensions, build time, skill level). "
        . "Not the website, availability, price, formats or shipping. Same language as the page. No marketing fluff.$extra\"}";
    $out = ['transient' => true];
    $raw = '';
    foreach (['', " Your summary must not start with 'This' or 'The page'. Make the subject of the page the grammatical subject, with a verb, e.g. 'The Pudel is a 4.9 m cutter designed for...' or 'Amazon has turned into...'."] as $i => $extra) {
        $p = openrouter_post($pdo, $ask($extra), 450);
        if (!isset($p['j'])) return $i ? $out : $p;
        $j = $p['j'];
        $out = ['tags' => normalize_tags((array)($j['tags'] ?? [])), 'new_tags' => normalize_tags((array)($j['new_tags'] ?? [])), 'category' => in_array($j['category'] ?? '', BM_CATEGORIES, true) ? $j['category'] : '', 'title' => valid_title((string)($j['title'] ?? '')),
                'summary' => valid_summary((string)($j['summary'] ?? ''), $format), 'cost' => $p['cost'] + ($out['cost'] ?? 0)];
        if ($out['summary'] !== '') break;
        $raw = $raw ?: clean_text(strip_tags((string)($j['summary'] ?? '')));
        error_log('openrouter: summary rejected: ' . mb_substr((string)($j['summary'] ?? ''), 0, 200));
    }
    if (($out['summary'] ?? '') === '' && mb_strlen($raw) >= 20 && mb_strlen($raw) <= 450 && !isset($out['transient'])) $out['summary'] = $raw;   // last resort: a plain "This page..." beats a fragment or nothing
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
        if (count($applied) < 1 || tag_in_text($t, $vocab[$t], "$text $body")) $applied[] = $t; else $held[] = $t;
    }
    $applied = array_slice(array_values(array_unique($applied)), 0, BM_MAX);
    $new = novel_tags($x['new_tags'] ?? [], $names, $channel, $url, $brands);
    $hay = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)) . ' ';
    foreach ($new as $i => $t) {
        if (count($applied) >= BM_MIN) break;
        $words = array_filter(preg_split('/\s+/u', mb_strtolower($t)), fn($w) => mb_strlen($w) >= 4);
        if ($words && !array_filter($words, fn($w) => !str_contains($hay, " $w ") && !str_contains($hay, " {$w}s "))) { $applied[] = $t; unset($new[$i]); }
    }
    return [$applied, array_slice(array_values(array_unique(array_diff(array_merge($new, $sugg), $applied))), 0, 3)];
}

/**
 * Tag one crawled page.
 * @return array{tags:string[],suggested:string[],category:string,summary:string,api:int,cost:float,llm_pending:bool}
 */
function tag_bookmark(string $url, array $c, array $vocab, PDO $pdo, int $uid = 0): array {
    $yt = youtube_id($url) !== null;
    $title = $c['title'] ?: $url;
    if (preg_match('/^(404\b|just a moment|attention required|access denied|are you a (human|robot)|page not found|not found|error 404|seite nicht gefunden|pagina non trovata)/i', trim($title))) return ['tags' => [], 'suggested' => [], 'category' => 'other', 'summary' => '', 'title' => title_from_url($url), 'api' => 0, 'cost' => 0.0, 'llm_pending' => false];   // dead page: nothing to tag, the URL slug is the best title left
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
    $category = $dr['category'] ?? ($x['category'] ?? '') ?: category_for($applied, $yt);
    if (!$format) $format = type_tag($category);
    return ['tags' => prune_types(array_merge($format, $applied)), 'suggested' => $sugg, 'category' => $category,
            'summary' => $x['summary'] ?? '', 'title' => $x['title'] ?? '', 'api' => $x ? 1 : 0, 'cost' => $x['cost'] ?? 0.0, 'llm_pending' => $deferred];
}

/** Link-type tag from the model's category when no structural format tag matched. */
function type_tag(string $category): array {
    $t = ['news' => 'article', 'article' => 'article', 'reference' => 'reference', 'tool' => 'tool', 'shopping' => 'product'][$category] ?? null;
    return $t ? [$t] : [];
}

/** A specific format (book, video, paper...) makes the generic link types (article, reference, tool) redundant. */
function prune_types(array $tags): array {
    $generic = ['article', 'reference', 'tool'];
    return array_diff(array_intersect($tags, BM_FORMAT_TAGS), $generic) ? array_values(array_diff($tags, $generic)) : array_values($tags);
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
        if ($d === null) { $pdo->prepare('UPDATE jobs SET llm_pending=0, llm_attempts=? WHERE bookmark_id=?')->execute([$attempts, $b['id']]); error_log("openrouter: gave up on #{$b['id']} after $attempts attempts"); return 'gaveup'; }
        $pdo->prepare('UPDATE jobs SET llm_attempts=?, llm_next_at=? WHERE bookmark_id=?')->execute([$attempts, time() + $d, $b['id']]);
        return 'retry';
    }
    $pdo->prepare('UPDATE jobs SET llm_pending=0, llm_attempts=?, refresh=0 WHERE bookmark_id=?')->execute([$attempts, $b['id']]);
    if (!$x) return 'gaveup';                                                         // permanent failure or cap reached
    if (!empty($x['summary'])) $pdo->prepare('UPDATE bookmarks SET summary=? WHERE id=? AND text_edited=0')->execute([$x['summary'], $b['id']]);
    if (!empty($x['title'])) $pdo->prepare('UPDATE bookmarks SET title=? WHERE id=? AND text_edited=0')->execute([$x['title'], $b['id']]);
    if (!$b['tags_edited'] || $b['refresh']) {      // edited bookmarks only during a refresh: their applied tags stay, new ones are added
        $cur = fn($sg) => array_column($pdo->query('SELECT tag FROM tags WHERE bookmark_id=' . (int)$b['id'] . ' AND suggested=' . $sg)->fetchAll(), 'tag');
        [$applied, $sugg] = merge_picks($x, $vocab, array_values(array_diff($cur(0), BM_FORMAT_TAGS)), $cur(1), '', $b['url'], [], $c['title'] . ' ' . $c['description'] . ' ' . ($x['summary'] ?? ''), $c['body']);
        $keep = array_values(array_intersect($cur(0), BM_FORMAT_TAGS)) ?: type_tag((string)($x['category'] ?? ''));
        $pdo->prepare('DELETE FROM tags WHERE bookmark_id=?')->execute([$b['id']]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO tags(bookmark_id, tag, suggested) VALUES(?,?,?)');
        foreach (prune_types(array_merge($keep, $applied)) as $t) $ins->execute([$b['id'], $t, 0]);
        foreach ($sugg as $t) $ins->execute([$b['id'], $t, 1]);
    }
    fts_reindex((int)$b['id']);
    return 'done';
}

<?php
// Hybrid tagger: rules -> keyword/alias hits -> YAKE phrases -> e5 embeddings (vocab + kNN over own bookmarks) -> fusion.
// No generative model. Embeddings come from a multilingual-e5-small GGUF served by a short-lived llama-server.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crawler.php';

const BM_CATEGORIES = ['dev', 'news', 'video', 'shopping', 'reference', 'article', 'tool', 'other'];
const BM_FORMAT_TAGS = ['video'];      // describe the medium; never count toward the 3-topic minimum
const BM_MIN = 3;
const BM_MAX = 6;
const BM_STOP = 'a aber about after all alle allem allen aller alles als also am an and andere anderen any are as at auch auf aus be been before bei being between bin bis bist both but by can could da damit dann das dass dein deine dem den der des dessen did die dies diese diesem diesen dieser dieses do doch does doing dort down du durch during each ein eine einem einen einer eines einmal er es etwas euch euer few for from fuer further für gegen get gewesen got hab habe haben had has hat hatte have he her hier hin hinter his how i ich if ihm ihn ihr ihre im in indem ins into is ist it its ja jede jedem jeden jeder jedes jetzt just kann kein keine like made make me mehr mein meine mich mir mit more most muss my nach new nicht no noch nor not nun nur ob oder of ohne on one only or other our out over own same see sehr sein seine she should sich sie sind so solche soll sollte some sondern sonst such than that the their them then there these they this to too two ueber um und under uns unser unter until up use used using very via vom von vor war waren warum was we weg weil weiter welche wenn wer werden were what when where which while who why wie wieder will wir wird wirst with wo wollen would wurde wurden you your zu zum zur zwischen über';
const BM_ALIASES = [
    'golang' => 'go', 'js' => 'javascript', 'machine learning' => 'ai', 'llm' => 'ai', 'artificial intelligence' => 'ai', 'open source' => 'opensource',
    'selfhosted' => 'self-hosted', 'self hosted' => 'self-hosted', 'homelab' => 'self-hosted', 'recipe' => 'food', 'cooking' => 'food', 'sqlite' => 'database',
    'sql' => 'database', 'postgresql' => 'database', 'docker' => 'devops', 'kubernetes' => 'devops', 'nginx' => 'devops', 'cybersecurity' => 'security',
    'podcast' => 'music', 'nachrichten' => 'news', 'how to' => 'tutorial', 'rezept' => 'food', 'politik' => 'politics',
];
/** YouTube's own category -> vocabulary tag */
const BM_YT_CATEGORY = ['music' => 'music', 'science & technology' => 'tech', 'education' => 'tutorial', 'gaming' => 'gaming', 'howto & style' => 'tutorial',
    'news & politics' => 'news', 'comedy' => 'fun', 'entertainment' => 'fun', 'film & animation' => 'film', 'sports' => 'sports', 'travel & events' => 'travel',
    'autos & vehicles' => 'cars', 'pets & animals' => 'animals'];

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

/** domain_rules.txt ("domain => tag, tag | category") for this URL; first match wins for the category. */
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

/** Vocabulary tags (and aliases) that occur as whole words in the text. */
function word_hits(array $names, string $text): array {
    $hay = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}+#.-]+/u', ' ', $text)) . ' ';
    $hit = [];
    foreach ($names as $t) if (mb_strlen($t) > 2 && (str_contains($hay, " $t ") || str_contains($hay, " {$t}s "))) $hit[] = $t;
    foreach (BM_ALIASES as $p => $t) if (in_array($t, $names, true) && str_contains($hay, " $p ")) $hit[] = $t;
    return array_values(array_unique($hit));
}

/** Compact YAKE: unsupervised keyphrases (1-2 words); lower score = better. Title is repeated 3x to weight it. */
function yake(string $title, string $text, int $top = 10): array {
    static $stop = null; $stop ??= array_flip(explode(' ', BM_STOP));
    $sents = [];
    foreach (preg_split('/(?<=[.!?;|])\s+|\n+|\s[-–—]\s/u', str_repeat("$title. ", 3) . $text, -1, PREG_SPLIT_NO_EMPTY) as $s) {
        if (preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}+#]*/u', $s, $m)) $sents[] = $m[0];
    }
    $tf = $cap = $sset = $pos = $L = $R = [];
    foreach ($sents as $si => $ws) foreach ($ws as $i => $raw) {
        $k = mb_strtolower($raw);
        if (isset($stop[$k]) || mb_strlen($k) < 3 || ctype_digit($k)) continue;
        $tf[$k] = ($tf[$k] ?? 0) + 1;
        $sset[$k][$si] = 1; $pos[$k][] = $si;
        if ($i > 0 && $raw !== $k && mb_substr($raw, 0, 1) === mb_strtoupper(mb_substr($raw, 0, 1))) $cap[$k] = ($cap[$k] ?? 0) + 1;
        $p = $i > 0 ? mb_strtolower($ws[$i - 1]) : null;
        if ($p !== null && !isset($stop[$p])) { $L[$k][$p] = 1; $R[$p][$k] = 1; }
    }
    if (!$tf) return [];
    $n = count($tf); $mean = array_sum($tf) / $n; $std = sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $tf)) / $n); $max = max($tf);
    $S = [];
    foreach ($tf as $k => $f) {
        sort($pos[$k]);
        $rel = 1 + (count($L[$k] ?? []) + count($R[$k] ?? [])) / $f * ($f / $max);
        $S[$k] = ($rel * log(3 + $pos[$k][intdiv(count($pos[$k]), 2)]))
               / (($cap[$k] ?? 0) / (1 + log($f)) + $f / ($mean + $std) / $rel + count($sset[$k]) / count($sents) / $rel);
    }
    $cand = [];
    foreach ($sents as $ws) {
        $ks = array_map('mb_strtolower', $ws);
        foreach ($ks as $i => $k) for ($len = 1; $len <= 2 && $i + $len <= count($ks); $len++) {
            $g = array_slice($ks, $i, $len);
            if (count(array_filter($g, fn($x) => isset($S[$x]))) < $len) break;   // stopword inside: stop extending
            $key = implode(' ', $g); $cand[$key] = ($cand[$key] ?? 0) + 1;
        }
    }
    $score = [];
    foreach ($cand as $g => $f) { $ws = explode(' ', $g); $score[$g] = array_product(array_map(fn($w) => $S[$w], $ws)) / ($f * (1 + array_sum(array_map(fn($w) => $S[$w], $ws)))); }
    asort($score);
    $out = [];
    foreach (array_keys($score) as $g) {
        foreach ($out as $o) if (array_intersect(explode(' ', $o), explode(' ', $g))) continue 2;   // no word overlap with a better phrase
        $out[] = $g;
        if (count($out) >= $top) break;
    }
    return $out;
}

/** Embeddings via a short-lived llama-server (multilingual-e5-small). Texts need their "query: " / "passage: " prefix. */
function embed(array $texts): array {
    static $proc = null; $port = (int)(cfg('embed_port') ?: 8099);
    if (!$proc) {
        $proc = proc_open([cfg('llama_server') ?: '/opt/llama.cpp/llama-server', '-m', cfg('embed_model') ?: '/opt/models/multilingual-e5-small-Q8_0.gguf', '--embeddings', '--pooling', 'mean',
            '-c', '512', '-t', (string)(cfg('embed_threads') ?: 2), '-np', '1', '--host', '127.0.0.1', '--port', (string)$port, '--no-webui'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        register_shutdown_function(function () use (&$proc) { if ($proc) { proc_terminate($proc); proc_close($proc); } });
        for ($i = 0; $i < 60 && !str_contains((string)@file_get_contents("http://127.0.0.1:$port/health"), 'ok'); $i++) usleep(500000);
    }
    $out = [];
    foreach (array_chunk($texts, 16) as $chunk) {
        $ch = curl_init("http://127.0.0.1:$port/v1/embeddings");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => json_encode(['model' => 'e5', 'input' => array_map(fn($t) => mb_substr($t, 0, 1500), $chunk)]), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $j = json_decode((string)curl_exec($ch), true);
        if (empty($j['data'])) throw new RuntimeException('embedding server failed');
        foreach ($j['data'] as $d) $out[] = $d['embedding'];
    }
    return $out;
}

function dot(array $a, array $b): float { $s = 0.0; foreach ($a as $i => $x) $s += $x * $b[$i]; return $s; }
function vec_pack(array $v): string { return pack('f*', ...$v); }
function vec_unpack(string $b): array { return array_values(unpack('f*', $b)); }

/** Embedding of every vocabulary tag description ("passage: ..."), cached in tag_vec until the text changes. */
function vocab_vectors(PDO $pdo, array $vocab): array {
    $have = [];
    foreach ($pdo->query('SELECT tag, h, vec FROM tag_vec') as $r) $have[$r['tag']] = $r;
    $todo = array_filter($vocab, fn($d, $t) => ($have[$t]['h'] ?? '') !== md5($d), ARRAY_FILTER_USE_BOTH);
    if ($todo) {
        $vs = embed(array_map(fn($d) => "passage: $d", array_values($todo)));
        $up = $pdo->prepare('INSERT OR REPLACE INTO tag_vec(tag,h,vec) VALUES(?,?,?)');
        foreach (array_keys($todo) as $i => $t) { $up->execute([$t, md5($todo[$t]), vec_pack($vs[$i])]); $have[$t] = ['vec' => vec_pack($vs[$i])]; }
    }
    return array_map(fn($t) => vec_unpack($have[$t]['vec']), array_combine(array_keys($vocab), array_keys($vocab)));
}

/** Tags of the user's most similar bookmarks (edited ones count 1.5x): tag => share of the neighbour weight. */
function knn_tags(PDO $pdo, int $uid, array $vec, int $k = 5, float $min = 0.94): array {
    $near = [];
    $st = $pdo->prepare('SELECT id, embedding, tags_edited FROM bookmarks WHERE user_id=? AND embedding IS NOT NULL');
    $st->execute([$uid]);
    foreach ($st as $b) { $s = dot($vec, vec_unpack($b['embedding'])); if ($s >= $min) $near[] = [$s * ($b['tags_edited'] ? 1.5 : 1), $b['id']]; }
    rsort($near);
    $w = []; $tot = 0;
    foreach (array_slice($near, 0, $k) as [$s, $id]) {
        $tot += $s;
        $t = $pdo->prepare('SELECT tag FROM tags WHERE bookmark_id=? AND suggested=0'); $t->execute([$id]);
        foreach ($t->fetchAll(PDO::FETCH_COLUMN) as $tag) $w[$tag] = ($w[$tag] ?? 0) + $s;
    }
    return $tot ? array_map(fn($x) => $x / $tot, $w) : [];
}

function category_for(array $tags, bool $yt): string {
    if ($yt) return 'video';
    if (array_intersect($tags, ['php', 'javascript', 'go', 'rust', 'devops', 'css', 'database', 'opensource', 'self-hosted', 'linux', 'security', 'docs'])) return 'dev';
    foreach (['news' => 'news', 'shopping' => 'shopping', 'tools' => 'tool', 'tutorial' => 'article'] as $t => $c) if (in_array($t, $tags, true)) return $c;
    return 'other';
}

/** A usable summary: 20-300 chars, not an echo of boilerplate; '' otherwise. */
function valid_summary(string $s): string {
    $s = clean_text(strip_tags($s));
    return (mb_strlen($s) < 20 || mb_strlen($s) > 300 || preg_match('/^(this (web)?page|diese seite)\b/i', $s)) ? '' : (mb_strlen($s) > 240 ? clean_cut($s, 240) : $s);
}

/** Description is missing, too long/short, junk (consent text, equals the title) or identical on another bookmark of the same site. */
function desc_needs_summary(PDO $pdo, int $uid, string $url, string $title, string $desc): bool {
    $d = clean_text($desc); $n = mb_strlen($d);
    if ($n < 40 || $n > 300 || mb_strtolower($d) === mb_strtolower(clean_text($title))) return true;
    if (preg_match('/^YouTube video by |cookie|consent|accept all|enable javascript|privacy policy|sign in|log in|subscribe to/i', $d)) return true;
    $q = $pdo->prepare('SELECT 1 FROM bookmarks WHERE user_id=? AND description=? AND url<>? AND url LIKE ? LIMIT 1');
    $q->execute([$uid, $desc, $url, '%//%' . host_of($url) . '/%']);
    return (bool)$q->fetchColumn();
}

/**
 * Optional OpenRouter step (cheap Gemini Flash-Lite): vocabulary picks, new tags, a one-sentence summary.
 * Capped per month in api_usage; returns [] on any problem so the local result is used.
 * @return array{tags:string[],new_tags:string[],summary:string,cost:float}|array{}
 */
function suggest_external(PDO $pdo, string $url, array $c, array $names): array {
    if (!($key = cfg('openrouter_key'))) return [];
    $month = date('Y-m');
    $pdo->prepare('INSERT OR IGNORE INTO api_usage(month,calls) VALUES(?,0)')->execute([$month]);
    $n = $pdo->prepare('SELECT calls FROM api_usage WHERE month=?'); $n->execute([$month]);
    if ((int)$n->fetchColumn() >= (int)(cfg('openrouter_cap') ?: 3000)) { error_log('openrouter: monthly call cap reached'); return []; }
    $pdo->prepare('UPDATE api_usage SET calls=calls+1 WHERE month=?')->execute([$month]);
    $yt = youtube_id($url) !== null;
    $prompt = 'Domain: ' . host_of($url) . "\nTitle: " . mb_substr($c['title'] ?: $url, 0, 200) . "\nDescription: " . mb_substr($c['description'], 0, 400)
        . ($yt ? "\nChannel: {$c['channel']}\nYouTube category: {$c['yt_category']}\nYouTube keywords: " . mb_substr($c['keywords'], 0, 300) : '')
        . "\nText: " . mb_substr($c['body'], 0, 1000) . "\nVocabulary: " . implode(', ', array_diff($names, BM_FORMAT_TAGS))
        . "\nReply with JSON only: {\"tags\": [2 to 5 tags chosen ONLY from the vocabulary; include only tags that clearly apply], \"new_tags\": [0 to 3 specific new lowercase tags of 1-2 words that are not in the vocabulary], \"summary\": \"one or two neutral sentences, max 200 characters, in the language of the page, no marketing fluff, not starting with 'This page'\"}";
    $ch = curl_init(cfg('openrouter_url') ?: 'https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'HTTP-Referer: ' . (cfg('base_url') ?: 'https://localhost'), 'X-Title: Bookmarks'],
        CURLOPT_POSTFIELDS => json_encode(['model' => cfg('openrouter_model') ?: 'google/gemini-2.5-flash-lite', 'temperature' => 0.2, 'max_tokens' => 300,
            'response_format' => ['type' => 'json_object'], 'messages' => [['role' => 'system', 'content' => 'You tag and summarize bookmarks. Reply with JSON only.'], ['role' => 'user', 'content' => $prompt]]])]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $r = json_decode((string)$raw, true);
    $ec = (int)($r['error']['code'] ?? 0);
    if ($raw === false || $code == 429 || $code >= 500 || $ec == 429 || $ec >= 500) { error_log('openrouter: transient failure (http ' . $code . '), will retry later'); return ['transient' => true]; }
    $j = json_decode((string)($r['choices'][0]['message']['content'] ?? ''), true);
    if (!is_array($j)) { error_log('openrouter: failed ' . substr(json_encode($r['error'] ?? curl_error($ch)), 0, 160)); return []; }
    $u = $r['usage'] ?? [];
    return ['tags' => normalize_tags((array)($j['tags'] ?? [])), 'new_tags' => normalize_tags((array)($j['new_tags'] ?? [])), 'summary' => valid_summary((string)($j['summary'] ?? '')),
            'cost' => ($u['prompt_tokens'] ?? 0) * (float)(cfg('openrouter_price_in') ?: 1e-7) + ($u['completion_tokens'] ?? 0) * (float)(cfg('openrouter_price_out') ?: 4e-7)];
}

/** z-score of the similarity between a page vector and every vocabulary tag description. */
function vocab_z(PDO $pdo, array $vocab, array $vec): array {
    $sims = [];
    foreach (vocab_vectors($pdo, $vocab) as $t => $v) $sims[$t] = dot($vec, $v);
    $mean = array_sum($sims) / count($sims);
    $std = sqrt(array_sum(array_map(fn($s) => ($s - $mean) ** 2, $sims)) / count($sims)) ?: 1;
    return array_map(fn($s) => ($s - $mean) / $std, $sims);
}

/** New-tag candidates that are not (stems of) vocabulary tags, not channel/host names. */
function novel_tags(array $cands, array $names, string $channel, string $url): array {
    $skip = preg_replace('/[^a-z0-9]/', '', mb_strtolower($channel . host_of($url)));
    return array_values(array_filter($cands, fn($p) => mb_strlen($p) <= 24 && !preg_match('/^\d/', $p) && !in_array($p, $names, true)
        && !array_filter($names, fn($n) => mb_strlen($n) >= 5 && mb_substr($n, 0, 5) === mb_substr($p, 0, 5)) && !str_contains($skip, preg_replace('/[^a-z0-9]/', '', $p))));
}

/** The model over-tags: a vocabulary pick is applied only if the embedding also ranks it in its top 10, otherwise it is just suggested. */
function merge_external(array $x, array $names, array $z, array $applied, array $sugg, string $channel, string $url): array {
    arsort($z); $top = array_slice(array_keys($z), 0, 10);
    foreach ($x['tags'] ?? [] as $t) {
        if (!in_array($t, $names, true) || in_array($t, BM_FORMAT_TAGS, true)) continue;
        if (in_array($t, $top, true) || in_array($t, $applied, true)) $applied[] = $t; else $sugg[] = $t;
    }
    return [array_slice(array_values(array_unique($applied)), 0, BM_MAX), array_merge(novel_tags($x['new_tags'] ?? [], $names, $channel, $url), $sugg)];
}

/** Seconds until the next deferred OpenRouter attempt after `$attempts` failures; null = give up. */
function llm_backoff(int $attempts): ?int { return [60, 300, 1800, 7200][$attempts - 1] ?? null; }

/**
 * Deferred OpenRouter step for a bookmark that already has local tags. Merges into its tags (never for tags_edited=1).
 * @return string done | retry | gaveup
 */
function llm_retry(PDO $pdo, array $b, array $vocab): string {
    $c = ['title' => (string)$b['title'], 'description' => (string)$b['description'], 'body' => (string)$b['body'], 'keywords' => '', 'channel' => '', 'yt_category' => ''];
    $x = suggest_external($pdo, $b['url'], $c, array_keys($vocab));
    $attempts = (int)$b['llm_attempts'] + 1;
    if (!empty($x['transient'])) {
        $d = llm_backoff($attempts);
        if ($d === null) { $pdo->prepare('UPDATE bookmarks SET llm_pending=0, llm_attempts=? WHERE id=?')->execute([$attempts, $b['id']]); error_log("openrouter: gave up on #{$b['id']} after $attempts attempts"); return 'gaveup'; }
        $pdo->prepare('UPDATE bookmarks SET llm_attempts=?, llm_next_at=? WHERE id=?')->execute([$attempts, time() + $d, $b['id']]);
        return 'retry';
    }
    $pdo->prepare('UPDATE bookmarks SET llm_pending=0, llm_attempts=? WHERE id=?')->execute([$attempts, $b['id']]);
    if (!$x) return 'gaveup';                                                         // permanent failure or cap reached
    if (!empty($x['summary'])) { $pdo->prepare('UPDATE bookmarks SET summary=? WHERE id=?')->execute([$x['summary'], $b['id']]); fts_reindex((int)$b['id']); }
    if (!$b['tags_edited']) {
        $cur = fn($sg) => array_column($pdo->query('SELECT tag FROM tags WHERE bookmark_id=' . (int)$b['id'] . ' AND suggested=' . $sg)->fetchAll(), 'tag');
        $applied = array_values(array_diff($cur(0), BM_FORMAT_TAGS)); $sugg = $cur(1);
        $z = $b['embedding'] ? vocab_z($pdo, $vocab, vec_unpack($b['embedding'])) : [];
        [$applied, $sugg] = merge_external($x, array_keys($vocab), $z, $applied, $sugg, '', $b['url']);
        $keep = array_values(array_intersect($cur(0), BM_FORMAT_TAGS));
        $pdo->prepare('DELETE FROM tags WHERE bookmark_id=?')->execute([$b['id']]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO tags(bookmark_id, tag, suggested) VALUES(?,?,?)');
        foreach (array_merge($keep, $applied) as $t) $ins->execute([$b['id'], $t, 0]);
        foreach (array_slice(array_values(array_unique(array_diff($sugg, $applied))), 0, 5) as $t) $ins->execute([$b['id'], $t, 1]);
        fts_reindex((int)$b['id']);
    }
    return 'done';
}

/**
 * Tag one crawled page.
 * @return array{tags:string[],suggested:string[],category:string,embedding:string,summary:string,api:int,cost:float,llm_pending:bool}
 */
function tag_bookmark(string $url, array $c, array $vocab, PDO $pdo, int $uid = 0): array {
    $yt = youtube_id($url) !== null;
    $title = $c['title'] ?: $url;
    $names = array_keys($vocab);
    $dr = domain_rule($url);
    $text = $c['description'] . ' ' . $c['keywords'] . ' ' . $c['body'];
    $score = [];                                                                 // tag => fused score
    $add = function (array $tags, float $w) use (&$score, $names) { foreach ($tags as $t) if (in_array($t, $names, true) || $w >= 1) $score[$t] = ($score[$t] ?? 0) + $w; };

    $add($dr['tags'], 1.0);                                                      // 1. domain rules
    $add(word_hits($names, "$title {$c['description']} {$c['keywords']}"), 1.0); // 2. keywords/aliases in title, description, meta keywords
    if ($yt && isset(BM_YT_CATEGORY[$k = mb_strtolower((string)$c['yt_category'])])) $add([BM_YT_CATEGORY[$k]], 1.0);
    $phrases = yake($title, $text);                                              // 3. YAKE phrases: vocabulary hits score, the rest become suggestions
    $add(word_hits($names, implode(' ', $phrases)), 0.5);

    $vec = embed(["query: $title. " . ($c['channel'] ?? '') . ' ' . mb_substr($text, 0, 900)])[0];   // 4. embedding vs. vocabulary
    $z = vocab_z($pdo, $vocab, $vec);
    $zmax = max($z);
    foreach ($z as $t => $zz) if ($zz >= (float)(cfg('embed_z') ?: 2.0) && $zz >= 0.6 * $zmax) $score[$t] = ($score[$t] ?? 0) + min(1, $zz / 3) * 0.6;
    if ($uid) foreach (knn_tags($pdo, $uid, $vec) as $t => $share) if (isset($score[$t]) || $share >= 0.6) $score[$t] = ($score[$t] ?? 0) + 0.4 * $share;   // booster, or strong agreement   // 5. the user's own similar bookmarks

    foreach (BM_FORMAT_TAGS as $f) unset($score[$f]);
    arsort($score);
    $applied = array_slice(array_keys(array_filter($score, fn($s) => $s >= 0.5)), 0, BM_MAX);
    $sugg = array_slice(array_keys(array_filter($score, fn($s) => $s < 0.5)), 0, 3);            // weaker vocabulary candidates
    // 6. OpenRouter only when local evidence is short or the page could not be read properly
    $x = []; $deferred = false;
    if (count($applied) < BM_MIN || ($c['source'] ?? 'direct') !== 'direct' || crawl_thin($c) || desc_needs_summary($pdo, $uid, $url, $title, $c['description'])) {
        try { $x = suggest_external($pdo, $url, $c, $names); } catch (Throwable $e) { error_log('openrouter: ' . $e->getMessage()); }
        if (!empty($x['transient'])) { $deferred = true; $x = []; }                 // keep local tags, retry later
        elseif ($x) [$applied, $sugg] = merge_external($x, $names, $z, $applied, $sugg, (string)($c['channel'] ?? ''), $url);
    }
    $need = $x ? max(0, BM_MIN - count($applied) - count($sugg)) : max(2, BM_MIN - count($applied) - count($sugg));
    $sugg = array_merge($sugg, array_slice(novel_tags($phrases, $names, (string)($c['channel'] ?? ''), $url), 0, $need));                        // 7. fill up to >= 3 applied+suggested
    $sugg = array_slice(array_values(array_unique(array_diff($sugg, $applied))), 0, 5);
    $format = ($yt || in_array('video', $dr['tags'], true)) ? ['video'] : [];
    return ['tags' => array_merge($format, $applied), 'suggested' => $sugg, 'category' => $dr['category'] ?? category_for($applied, $yt), 'embedding' => vec_pack($vec), 'summary' => $x['summary'] ?? '', 'api' => $x ? 1 : 0, 'cost' => $x['cost'] ?? 0.0, 'llm_pending' => $deferred];
}

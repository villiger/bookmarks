<?php
require_once __DIR__ . '/config.php';

const BM_CATEGORIES = ['dev', 'news', 'video', 'shopping', 'reference', 'article', 'tool', 'other'];

/** Closed vocabulary: tags are an enum, the model cannot invent any (except the single optional new_tag). */
function llama_schema(array $vocab, int $min, int $max): string {
    return json_encode([
        'type' => 'object',
        'properties' => [
            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_values($vocab)], 'minItems' => $min, 'maxItems' => $max],
            'category' => ['type' => 'string', 'enum' => BM_CATEGORIES],
            'new_tag' => ['type' => 'string', 'maxLength' => 24],
        ],
        'required' => ['tags', 'category'],
    ]);
}

function wrapper_prefix(): string {
    static $w = null;
    if ($w !== null) return $w;
    $conf = cfg('llama_wrapper') ?? 'auto';
    $mb = (int)(cfg('llama_mem_mb') ?: 600);
    if ($conf !== 'auto') return $w = $conf;
    // Prefer a transient cgroup scope with a hard memory cap
    foreach (["systemd-run --user --scope -q -p MemoryMax={$mb}M -p MemorySwapMax=0", "systemd-run --scope -q -p MemoryMax={$mb}M -p MemorySwapMax=0"] as $cand) {
        exec($cand . ' true 2>/dev/null', $o, $rc);
        if ($rc === 0) return $w = $cand;
    }
    // Fallback: virtual memory limit (generous, because the model is mmapped)
    return $w = 'ulimit -v ' . (($mb + 1400) * 1024) . ' &&';
}

/** Run llama-cli once. Returns generated text (stdout). Throws on failure. */
function llama_run(string $prompt, int $maxTokens, ?string $jsonSchema, array $shots = []): string {
    $bin = cfg('llama_cli'); $model = cfg('llama_model');
    if (!is_executable($bin) || !is_file($model)) throw new RuntimeException('llama.cpp or model not installed');
    $chatml = "<|im_start|>system\nYou are a precise assistant that organizes bookmarks. Reply with JSON only.<|im_end|>\n";
    foreach ($shots as [$q, $a]) $chatml .= "<|im_start|>user\n$q<|im_end|>\n<|im_start|>assistant\n$a<|im_end|>\n";
    $chatml .= "<|im_start|>user\n$prompt<|im_end|>\n<|im_start|>assistant\n";
    $cmd = wrapper_prefix() . ' ' . escapeshellarg($bin)
        . ' -m ' . escapeshellarg($model)
        . ' -c ' . (int)cfg('llama_ctx') . ' -t ' . (int)cfg('llama_threads')
        . ' -n ' . $maxTokens . ' --temp 0.2 -no-cnv --no-display-prompt --no-warmup'
        . ($jsonSchema ? ' --json-schema ' . escapeshellarg($jsonSchema) : '')
        . ' -p ' . escapeshellarg($chatml);
    $pipes = [];
    $proc = proc_open(['sh', '-c', $cmd . ' </dev/null'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('cannot start llama');
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = $err = ''; $start = time(); $limit = (int)cfg('llama_timeout') ?: 120;
    while (true) {
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) break;
        if (time() - $start > $limit) { proc_terminate($proc, 9); throw new RuntimeException('llama timeout'); }
        usleep(100000);
    }
    $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = $st['exitcode'] >= 0 ? $st['exitcode'] : proc_close($proc);
    if ($rc !== 0 && trim($out) === '') throw new RuntimeException("llama exit $rc: " . substr(trim($err), -300));
    return $out;
}

function normalize_tags(array $tags): array {
    $out = [];
    foreach ($tags as $t) {
        if (!is_string($t)) continue;
        $t = mb_strtolower(trim($t));
        $t = trim(preg_replace('/[^\p{L}\p{N} +#.-]+/u', '', $t));
        if ($t === '' || mb_strlen($t) > 24) continue;
        $out[$t] = true;
    }
    return array_slice(array_keys($out), 0, 5);
}


/** Known vocabulary = seed_tags_file merged with the user's most-used tags (user's first). */
function known_tags(PDO $pdo, int $uid, int $existingLimit = 30): array {
    $seed = [];
    $f = cfg('seed_tags_file');
    if ($f && is_file($f)) $seed = normalize_tags_all(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $q = $pdo->prepare("SELECT t.tag FROM tags t JOIN bookmarks b ON b.id=t.bookmark_id WHERE b.user_id=? AND t.suggested=0 GROUP BY t.tag ORDER BY COUNT(*) DESC LIMIT " . (int)$existingLimit);
    $q->execute([$uid]);
    $used = $q->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_merge($used, $seed)));
}

function normalize_tags_all(array $tags): array {
    $out = [];
    foreach ($tags as $t) { $t = mb_strtolower(trim((string)$t)); if ($t !== '' && $t[0] !== '#') $out[$t] = true; }
    return array_keys($out);
}

/** domain_rules.txt -> [domain => ['tags'=>[], 'category'=>?string]] */
function domain_rules(): array {
    static $rules = null;
    if ($rules !== null) return $rules;
    $rules = [];
    $f = cfg('domain_rules_file') ?: __DIR__ . '/../domain_rules.txt';
    foreach (is_file($f) ? file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=>')) continue;
        [$dom, $rest] = array_map('trim', explode('=>', $line, 2));
        [$tags, $cat] = array_pad(array_map('trim', explode('|', $rest, 2)), 2, '');
        $rules[strtolower($dom)] = ['tags' => normalize_tags_all(array_filter(array_map('trim', explode(',', $tags)))), 'category' => in_array($cat, BM_CATEGORIES, true) ? $cat : null];
    }
    return $rules;
}

function host_of(string $url): string { return preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST) ?: '')); }

/** @return array{tags:string[],category:?string} */
function apply_domain_rules(string $url): array {
    $host = host_of($url); $tags = []; $cat = null;
    foreach (domain_rules() as $dom => $r) {
        if ($host === $dom || str_ends_with($host, '.' . $dom)) {
            $tags = array_merge($tags, $r['tags']);
            $cat = $cat ?? $r['category'];   // first (listed earlier) wins, e.g. music.youtube.com before youtube.com
        }
    }
    return ['tags' => array_values(array_unique($tags)), 'category' => $cat];
}

const BM_ALIASES = [
    'golang' => 'go', 'js' => 'javascript', 'machine learning' => 'ai', 'llm' => 'ai', 'artificial intelligence' => 'ai',
    'open source' => 'opensource', 'selfhosted' => 'self-hosted', 'self hosted' => 'self-hosted', 'recipe' => 'food',
    'recipes' => 'food', 'cooking' => 'food', 'ingredients' => 'food', 'sql' => 'database', 'sqlite' => 'database', 'postgresql' => 'database',
    'css3' => 'css', 'nginx' => 'devops', 'docker' => 'devops', 'kubernetes' => 'devops', 'cybersecurity' => 'security',
    'podcast' => 'music', 'musik' => 'music', 'nachrichten' => 'news', 'tutorial' => 'tutorial', 'how to' => 'tutorial',
];

/** Does the page text give any evidence for this tag? (guards against the small model's wild guesses) */
function tag_supported(string $tag, string $hayRaw): bool {
    $hay = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}+#.-]+/u', ' ', $hayRaw)) . ' ';
    $words = [$tag, str_replace('-', ' ', $tag)];
    foreach (BM_ALIASES as $phrase => $t) if ($t === $tag) $words[] = $phrase;
    foreach (BM_SUPPORT as $phrase => $t) if ($t === $tag) $words[] = $phrase;
    foreach ($words as $w) {
        if (str_contains($hay, ' ' . $w . ' ') || str_contains($hay, ' ' . $w . 's ')) return true;
        if (mb_strlen($w) >= 5 && str_contains($hay, ' ' . mb_substr($w, 0, 5))) return true;   // crude stem
    }
    return false;
}

/** Extra evidence words (not strong enough to tag on their own). */
const BM_SUPPORT = [
    'song' => 'music', 'album' => 'music', 'lyrics' => 'music', 'band' => 'music', 'remaster' => 'music', 'guitar' => 'music', 'episode' => 'music',
    'gpu' => 'hardware', 'cpu' => 'hardware', 'keyboard' => 'hardware', 'chip' => 'hardware', 'compiler' => 'tools', 'cli' => 'tools', 'app' => 'tools',
    'programming' => 'tutorial', 'guide' => 'tutorial', 'learn' => 'tutorial', 'lecture' => 'science', 'physics' => 'science', 'research' => 'science', 'study' => 'science',
    'neural' => 'ai', 'model' => 'ai', 'inference' => 'ai', 'gpt' => 'ai', 'html' => 'design', 'layout' => 'design', 'ui' => 'design', 'ux' => 'design',
    'bank' => 'finance', 'stock' => 'finance', 'price' => 'shopping', 'cart' => 'shopping', 'buy' => 'shopping', 'chf' => 'shopping', 'ikea' => 'shopping',
    'hosting' => 'self-hosted', 'server' => 'self-hosted', 'docker' => 'self-hosted', 'game' => 'gaming', 'games' => 'gaming', 'vpn' => 'security', 'encryption' => 'security',
    'hotel' => 'travel', 'flight' => 'travel', 'recipe' => 'food', 'ingredients' => 'food', 'sql' => 'database', 'queries' => 'database',
];

/** Keyword matching of the vocabulary (+aliases) against meta keywords/title/description. */
function keyword_tags(array $known, string $keywords, string $title, string $desc): array {
    $norm = fn($s) => ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}+#.-]+/u', ' ', $s)) . ' ';
    $hay = $norm("$title $desc $keywords");
    $kw = array_map('trim', explode(',', mb_strtolower($keywords)));
    $hit = [];
    foreach ($known as $t) {
        if (mb_strlen($t) <= 2) { if (in_array($t, $kw, true)) $hit[] = $t; continue; }   // 'go' etc. only from meta keywords
        if (str_contains($hay, ' ' . $t . ' ') || str_contains($hay, ' ' . $t . 's ')) $hit[] = $t;
    }
    foreach (BM_ALIASES as $phrase => $tag) {
        if (in_array($tag, $known, true) && !in_array($tag, $hit, true) && str_contains($hay, ' ' . $phrase . ' ')) $hit[] = $tag;
    }
    return $hit;
}

function category_from_tags(array $tags, bool $yt): string {
    if ($yt) return 'video';
    $dev = ['php', 'javascript', 'go', 'rust', 'devops', 'css', 'database', 'opensource', 'self-hosted', 'linux', 'security'];
    if (array_intersect($tags, $dev)) return 'dev';
    foreach (['news' => 'news', 'shopping' => 'shopping', 'docs' => 'reference', 'tools' => 'tool', 'tutorial' => 'article'] as $t => $c) if (in_array($t, $tags, true)) return $c;
    return 'other';
}

function tag_prompt(string $host, string $title, string $channel, string $desc, string $text, array $vocab, array $already, int $slots): string {
    return "Domain: $host\nTitle: " . mb_substr($title, 0, 200)
        . ($channel !== '' ? "\nChannel: $channel" : '')
        . "\nDescription: " . mb_substr($desc, 0, 300)
        . "\nText: $text"
        . "\nAllowed tags: " . implode(', ', $vocab)
        . "\nAlready chosen: " . ($already ? implode(', ', $already) : 'none')
        . "\nChoose up to $slots more tags that clearly describe the page, ONLY from the allowed tags (fewer is better; none is fine). Add new_tag only if no allowed tag fits.";
}

function builtin_shots(): array {
    $v = ['php', 'javascript', 'rust', 'linux', 'devops', 'tools', 'opensource', 'food', 'travel', 'shopping', 'hardware', 'news', 'music', 'tutorial'];
    return [
        [tag_prompt('github.com', 'BurntSushi/ripgrep', '', 'ripgrep recursively searches directories for a regex pattern', 'Fast grep alternative written in Rust, respects gitignore', $v, ['opensource'], 3),
         '{"tags":["rust","tools"],"category":"dev"}'],
        [tag_prompt('example-shop.ch', 'Mechanical Keyboard 75% Hot-swappable', '', 'Wireless keyboard, CHF 129', 'Add to cart. Delivery 2-3 days. Switches: linear.', $v, ['shopping'], 3),
         '{"tags":["hardware"],"category":"shopping"}'],
        [tag_prompt('someblog.example', 'Why I stopped making to-do lists', '', 'A personal essay about attention', 'Last year I threw away my lists and started noticing what actually mattered to me.', $v, [], 4),
         '{"tags":[],"category":"article"}'],
    ];
}

/** User-corrected bookmarks from the same domain make the best few-shot examples. */
function user_shots(PDO $pdo, int $uid, string $host, array $vocab): array {
    if ($uid <= 0 || $host === '') return [];
    $st = $pdo->prepare('SELECT id, title, description, body, url, category FROM bookmarks WHERE user_id=? AND tags_edited=1 ORDER BY id DESC LIMIT 200');
    $st->execute([$uid]);
    $shots = [];
    foreach ($st->fetchAll() as $b) {
        if (host_of($b['url']) !== $host) continue;
        $t = $pdo->prepare('SELECT tag FROM tags WHERE bookmark_id=? AND suggested=0');
        $t->execute([$b['id']]);
        $tags = $t->fetchAll(PDO::FETCH_COLUMN);
        if (!$tags) continue;
        $shots[] = [tag_prompt($host, (string)$b['title'], '', (string)$b['description'], mb_substr((string)$b['body'], 0, 300), array_slice($vocab, 0, 30), [], 4),
                    json_encode(['tags' => array_slice($tags, 0, 4), 'category' => $b['category'] ?: 'other'])];
        if (count($shots) >= 2) break;
    }
    return $shots;
}

/**
 * Full tagging pipeline: rules first, LLM only to fill remaining slots.
 * @param array $c crawl() result
 * @return array{tags:string[],suggested:string[],category:string,llm:bool}
 */
function tag_bookmark(string $url, array $c, array $known, ?PDO $pdo = null, int $uid = 0): array {
    $yt = youtube_id($url) !== null;
    $title = $c['title'] ?: $url;
    $dr = apply_domain_rules($url);
    $tags = array_values(array_unique(array_merge($dr['tags'], keyword_tags($known, $c['keywords'], $title, $c['description']))));
    $tags = array_slice($tags, 0, 4);
    $category = $dr['category'];
    if (count($tags) >= 3) {
        return ['tags' => $tags, 'suggested' => [], 'category' => $category ?? category_from_tags($tags, $yt), 'llm' => false];
    }
    // LLM fills the remaining slots from the closed vocabulary
    $vocab = array_values(array_diff($known, $tags));
    $slots = 4 - count($tags);
    $host = host_of($url);
    $text = mb_substr(clean_text($c['body']), 0, max(300, 1200 - mb_strlen($title) - mb_strlen($c['description'])));
    mt_srand(crc32($url));                     // deterministic shuffle: the 0.5B model is biased to the first enum entries
    shuffle($vocab);
    $user = tag_prompt($host, $title, $yt ? (string)($c['channel'] ?? '') : '', $c['description'], $text, $vocab, $tags, $slots);
    $shots = ($pdo ? user_shots($pdo, $uid, $host, $vocab) : []) ?: builtin_shots();
    $schema = llama_schema($vocab, 0, $slots);
    $raw = llama_run($user, 100, $schema, $shots);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    $j = ($a !== false && $b !== false) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
    if (!is_array($j) || !isset($j['tags']) || !is_array($j['tags'])) throw new RuntimeException('LLM returned invalid JSON: ' . substr($raw, 0, 200));
    $evidence = $title . ' ' . $c['description'] . ' ' . $c['keywords'] . ' ' . ($c['channel'] ?? '') . ' ' . $c['body'];
    $add = array_values(array_filter(array_intersect(normalize_tags($j['tags']), $vocab), fn($t) => tag_supported($t, $evidence)));
    $tags = array_slice(array_values(array_unique(array_merge($tags, $add))), 0, 4);
    $sugg = [];
    if (!empty($j['new_tag']) && is_string($j['new_tag'])) {
        $n = normalize_tags([$j['new_tag']]);
        if ($n && !in_array($n[0], $known, true) && !in_array($n[0], $tags, true)) $sugg[] = $n[0];
    }
    $llmCat = in_array($j['category'] ?? '', BM_CATEGORIES, true) ? $j['category'] : 'other';
    return ['tags' => $tags, 'suggested' => $sugg, 'category' => $category ?? $llmCat, 'llm' => true];
}

function llm_summary(string $title, string $body): string {
    $prompt = "Write one short sentence (max 30 words) summarizing this web page.\nTitle: " . mb_substr($title, 0, 200) . "\nText: " . mb_substr($body, 0, 1200) . "\nSummary:";
    $raw = llama_run($prompt, 70, null);
    $raw = trim(preg_replace('/\[end of text\]|<\|im_end\|>/', '', $raw));
    return mb_substr(preg_replace('/\s+/u', ' ', $raw), 0, 300);
}

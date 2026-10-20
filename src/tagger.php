<?php
require_once __DIR__ . '/config.php';

const BM_CATEGORIES = ['dev', 'news', 'video', 'shopping', 'reference', 'article', 'tool', 'other'];

function llama_schema(): string {
    return json_encode([
        'type' => 'object',
        'properties' => [
            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 24], 'minItems' => 1, 'maxItems' => 5],
            'category' => ['type' => 'string', 'enum' => BM_CATEGORIES],
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
function llama_run(string $prompt, int $maxTokens, ?string $jsonSchema): string {
    $bin = cfg('llama_cli'); $model = cfg('llama_model');
    if (!is_executable($bin) || !is_file($model)) throw new RuntimeException('llama.cpp or model not installed');
    $chatml = "<|im_start|>system\nYou are a precise assistant that organizes bookmarks.<|im_end|>\n<|im_start|>user\n$prompt<|im_end|>\n<|im_start|>assistant\n";
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

/** @return array{tags:string[],category:string} */
function llm_tag(string $title, string $desc, string $body, array $existingTags): array {
    $hint = $existingTags ? "Prefer these existing tags when they fit: " . implode(', ', $existingTags) . ".\n" : '';
    $prompt = "Classify this bookmark. Give 1-5 short lowercase tags and one category (" . implode(', ', BM_CATEGORIES) . ").\n$hint"
        . "Title: " . mb_substr($title, 0, 200) . "\nDescription: " . mb_substr($desc, 0, 400) . "\nText: " . mb_substr($body, 0, 1000)
        . "\nAnswer as JSON.";
    $raw = llama_run($prompt, 100, llama_schema());
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    $j = ($a !== false && $b !== false) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
    if (!is_array($j) || !isset($j['tags']) || !is_array($j['tags'])) throw new RuntimeException('LLM returned invalid JSON: ' . substr($raw, 0, 200));
    $cat = in_array($j['category'] ?? '', BM_CATEGORIES, true) ? $j['category'] : 'other';
    return ['tags' => normalize_tags($j['tags']), 'category' => $cat];
}

function llm_summary(string $title, string $body): string {
    $prompt = "Write one short sentence (max 30 words) summarizing this web page.\nTitle: " . mb_substr($title, 0, 200) . "\nText: " . mb_substr($body, 0, 1200) . "\nSummary:";
    $raw = llama_run($prompt, 70, null);
    $raw = trim(preg_replace('/\[end of text\]|<\|im_end\|>/', '', $raw));
    return mb_substr(preg_replace('/\s+/u', ' ', $raw), 0, 300);
}

/** Known vocabulary = seed_tags_file merged with most-used existing tags (seed first). */
function known_tags(PDO $pdo, int $uid, int $existingLimit = 30): array {
    $seed = [];
    $f = cfg('seed_tags_file');
    if ($f && is_file($f)) $seed = normalize_tags_all(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $q = $pdo->prepare("SELECT t.tag FROM tags t JOIN bookmarks b ON b.id=t.bookmark_id WHERE b.user_id=? GROUP BY t.tag ORDER BY COUNT(*) DESC LIMIT " . (int)$existingLimit);
    $q->execute([$uid]);
    $used = $q->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_merge($used, $seed)));
}

function normalize_tags_all(array $tags): array {
    $out = [];
    foreach ($tags as $t) { $t = mb_strtolower(trim((string)$t)); if ($t !== '' && $t[0] !== '#') $out[$t] = true; }
    return array_keys($out);
}

/**
 * Skip-the-LLM shortcut: if >=2 known tags occur in keywords/title/description, use them.
 * @return ?array{tags:string[],category:string}
 */
function match_known_tags(array $known, string $keywords, string $title, string $desc, bool $youtube): ?array {
    $hay = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}+#.-]+/u', ' ', "$keywords $title $desc")) . ' ';
    $hit = [];
    foreach ($known as $t) {
        if (strpos($hay, ' ' . $t . ' ') !== false || strpos($hay, ' ' . $t . 's ') !== false) $hit[] = $t;
    }
    if ($youtube && !in_array('video', $hit, true)) $hit[] = 'video';
    if (count($hit) < 2) return null;
    $hit = array_slice($hit, 0, 5);
    $dev = ['php', 'javascript', 'go', 'rust', 'devops', 'css', 'database', 'opensource', 'self-hosted', 'linux'];
    $cat = $youtube ? 'video' : (array_intersect($hit, $dev) ? 'dev' : (in_array('news', $hit) ? 'news' : (in_array('shopping', $hit) ? 'shopping' : (in_array('docs', $hit) ? 'reference' : (in_array('tools', $hit) ? 'tool' : (in_array('tutorial', $hit) ? 'article' : 'other'))))));
    return ['tags' => $hit, 'category' => $cat];
}

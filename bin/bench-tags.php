#!/usr/bin/env php
<?php
// Benchmark: crawl + tag a list of URLs WITHOUT touching any user's data (in-memory DB; the OpenRouter call is real).
// Usage: php bin/bench-tags.php bench/urls.txt
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/tagger.php';
$pdo = new PDO('sqlite::memory:');
migrate($pdo);
$vocab = vocab($pdo, 0);
$stats = [];
printf("%-60s | %-42s | %-9s | %4s | %s\n", 'url', 'tags (+suggested)', 'category', 'sec', 'title / summary');
foreach (array_filter(array_map('trim', file($argv[1], FILE_IGNORE_NEW_LINES))) as $u) {
    $t0 = microtime(true);
    try {
        [$c, $src] = crawl_resilient(['url' => $u, 'scheme_guessed' => 0] + array_fill_keys(['client_title', 'client_desc', 'client_image', 'client_favicon', 'client_keywords', 'client_text'], null));
        $c['source'] = $src;
        $r = tag_bookmark($u, $c, $vocab, $pdo);
        $topic = count(array_diff($r['tags'], BM_FORMAT_TAGS)); $sec = microtime(true) - $t0;
        $stats[] = [$topic, $topic + count($r['suggested']), $sec, $r['api'], $r['cost']];
        printf("%-60s | %-42s | %-9s | %4.1f | %s\n", mb_substr($u, 0, 60), implode(',', $r['tags']) . ($r['suggested'] ? ' (+' . implode(',', $r['suggested']) . ')' : ''), $r['category'], $sec, mb_substr($c['title'], 0, 40) . ' [' . $src . '] ' . mb_substr($r['summary'], 0, 90));
    } catch (Throwable $e) {
        printf("%-60s | ERROR %s\n", mb_substr($u, 0, 60), mb_substr($e->getMessage(), 0, 60));
    }
}
if ($stats) {
    $n = count($stats); $p = fn($f) => round(100 * count(array_filter($stats, $f)) / $n);
    printf("\nSUMMARY n=%d | applied>=3: %d%% | applied+suggested>=3: %d%% | avg applied %.1f | avg applied+suggested %.1f | avg %.1fs | api calls %d | est. cost $%.5f ($%.2f per 1000 bookmarks)\n", $n,
        $p(fn($x) => $x[0] >= 3), $p(fn($x) => $x[1] >= 3), array_sum(array_column($stats, 0)) / $n, array_sum(array_column($stats, 1)) / $n, array_sum(array_column($stats, 2)) / $n,
        array_sum(array_column($stats, 3)), array_sum(array_column($stats, 4)), 1000 * array_sum(array_column($stats, 4)) / $n);
}

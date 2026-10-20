#!/usr/bin/env php
<?php
// Benchmark: crawl + tag a list of URLs WITHOUT touching any user's data.
// Usage: php bin/bench-tags.php bench/urls.txt > bench/after.txt
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../src/crawler.php';
require_once __DIR__ . '/../src/tagger.php';
$pdo = new PDO('sqlite::memory:');
$pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
$vocab = vocab($pdo, 0);
$urls = array_filter(array_map('trim', file($argv[1], FILE_IGNORE_NEW_LINES)));
printf("%-62s | %-40s | %-9s | %-4s | %s\n", 'url', 'tags', 'category', 'emb', 'sec');
$stats = [];
foreach ($urls as $u) {
    $t0 = microtime(true);
    try {
        $c = crawl($u);
        $r = tag_bookmark($u, $c, $vocab, $pdo); $ti = mb_substr($c["title"], 0, 45);
        $topic = array_diff($r['tags'], BM_FORMAT_TAGS); $stats[] = [count($topic), count($topic) + count($r['suggested']), true, microtime(true) - $t0];
        printf("%-62s | %-40s | %-9s | %-4s | %.1f%s\n", mb_substr($u, 0, 62), implode(',', $r['tags']), $r['category'], 'yes', microtime(true) - $t0,
            ' | ' . $ti . ($r['suggested'] ? '  (suggested: ' . implode(',', $r['suggested']) . ')' : ''));
    } catch (Throwable $e) {
        printf("%-62s | ERROR %s\n", mb_substr($u, 0, 62), mb_substr($e->getMessage(), 0, 60));
    }
}

if ($stats) {
    $n = count($stats);
    $p = fn($f) => round(100 * count(array_filter($stats, $f)) / $n);
    printf("\nSUMMARY n=%d | applied>=3: %d%% | applied+suggested>=3: %d%% | avg applied %.1f | avg applied+suggested %.1f | avg %.1fs\n", $n,
        $p(fn($x) => $x[0] >= 3), $p(fn($x) => $x[1] >= 3), array_sum(array_column($stats, 0)) / $n, array_sum(array_column($stats, 1)) / $n, array_sum(array_column($stats, 3)) / $n);
}

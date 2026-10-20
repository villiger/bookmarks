#!/usr/bin/env php
<?php
// Benchmark: crawl + tag a list of URLs WITHOUT touching any user's data.
// Usage: php bin/bench-tags.php bench/urls.txt > bench/after.txt
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../src/crawler.php';
require_once __DIR__ . '/../src/tagger.php';
$pdo = new PDO('sqlite::memory:');
$pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
$known = known_tags($pdo, 0);
$urls = array_filter(array_map('trim', file($argv[1], FILE_IGNORE_NEW_LINES)));
printf("%-62s | %-40s | %-9s | %-4s | %s\n", 'url', 'tags', 'category', 'llm', 'sec');
foreach ($urls as $u) {
    $t0 = microtime(true);
    try {
        $c = crawl($u);
        $r = tag_bookmark($u, $c, $known);
        printf("%-62s | %-40s | %-9s | %-4s | %.1f%s\n", mb_substr($u, 0, 62), implode(',', $r['tags']), $r['category'], $r['llm'] ? 'yes' : 'no', microtime(true) - $t0,
            $r['suggested'] ? '  (suggested: ' . implode(',', $r['suggested']) . ')' : '');
    } catch (Throwable $e) {
        printf("%-62s | ERROR %s\n", mb_substr($u, 0, 62), mb_substr($e->getMessage(), 0, 60));
    }
}

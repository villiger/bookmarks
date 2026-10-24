#!/usr/bin/env php
<?php
// Run every minute (systemd timer / cron); flock-protected. Crawls and tags pending bookmarks, then retries deferred OpenRouter steps.
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../src/pipeline.php';

$lock = fopen(cfg('lock_path'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);   // another worker is running

$max = (int)(cfg('max_attempts') ?: 3);
$say = fn(string $line) => print(date('c') . " $line\n");
fail_exhausted($max);
while ($b = next_pending($max)) $say(process_bookmark($b, $max));
foreach (retry_deferred_llm() as $line) $say($line);

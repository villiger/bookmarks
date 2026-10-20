#!/usr/bin/env php
<?php
// Run every minute (systemd timer / cron); flock-protected. Crawls and tags pending bookmarks, then retries deferred OpenRouter steps.
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/tagger.php';

$lock = fopen(cfg('lock_path'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);   // another worker is running

$pdo = db();
$max = (int)(cfg('max_attempts') ?: 3);

while (true) {
    $st = $pdo->prepare("SELECT * FROM bookmarks WHERE status='pending' AND attempts < ? ORDER BY id LIMIT 1");
    $st->execute([$max]);
    $b = $st->fetch();
    if (!$b) break;
    $id = (int)$b['id'];
    $pdo->prepare('UPDATE bookmarks SET attempts = attempts + 1 WHERE id=?')->execute([$id]);
    $attempts = (int)$b['attempts'] + 1;
    $t0 = microtime(true);
    try {
        [$c, $src] = crawl_resilient($b);
        // Final URL after redirects, cleaned; merge into an existing bookmark if it's a duplicate
        $finalUrl = clean_url($c['final_url'] ?? $b['url']);
        $finalKey = url_key($finalUrl);
        $dup = $pdo->prepare('SELECT id FROM bookmarks WHERE user_id=? AND url_key=? AND id<>?');
        $dup->execute([$b['user_id'], $finalKey, $id]);
        if ($dup->fetchColumn()) { delete_bookmark($id, (int)$b['user_id']); echo date('c') . " merged #$id into existing {$finalUrl}\n"; continue; }
        $titleOrig = $c['title'] ?: $b['title'] ?: $b['url'];
        $title = clean_title($titleOrig, $finalUrl, (string)$c['site_name']);
        $r = tag_bookmark($finalUrl, $c, vocab($pdo, (int)$b['user_id']), $pdo, (int)$b['user_id']);
        if (!empty($r['title'])) $title = $r['title'];   // the model replaced a bad crawled title (the original stays in title_orig)
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE bookmarks SET title=CASE WHEN text_edited=1 THEN title ELSE ? END, title_orig=?, description=?, summary=CASE WHEN text_edited=1 THEN summary ELSE ? END, favicon=?, image=?, body=?, category=?, status='done', error=NULL, crawl_source=?, url=?, url_key=?, scheme_guessed=0 WHERE id=?")
            ->execute([$title, $titleOrig, $c['description'], $r['summary'], $c['favicon'], $c['image'], $c['body'], $r['category'], $src, $finalUrl, $finalKey, $id]);
        // Tags: replaced on every re-process, except bookmarks the user corrected: those are untouched, or (refresh=1) keep their applied tags and get the new ones on top.
        if (!$b['tags_edited'] || $b['refresh']) {
            $cur = $b['tags_edited'] ? array_column($pdo->query("SELECT tag FROM tags WHERE bookmark_id=$id AND suggested=0")->fetchAll(), 'tag') : [];
            $pdo->prepare('DELETE FROM tags WHERE bookmark_id=?')->execute([$id]);
            $ins = $pdo->prepare('INSERT OR IGNORE INTO tags(bookmark_id, tag, suggested) VALUES(?,?,?)');
            foreach (array_merge($r['tags'], $cur) as $t) $ins->execute([$id, $t, 0]);
            foreach (array_diff($r['suggested'], $cur) as $t) $ins->execute([$id, $t, 1]);
        }
        $pdo->prepare('UPDATE bookmarks SET llm_pending=?, llm_attempts=?, llm_next_at=?, refresh=? WHERE id=?')
            ->execute([(int)$r['llm_pending'], (int)$r['llm_pending'], time() + ($r['llm_pending'] ? llm_backoff(1) : 0), (int)($r['llm_pending'] && $b['refresh']), $id]);
        $pdo->commit();
        fts_reindex($id);
        echo date('c') . " done #$id {$b['url']} [" . implode(',', $r['tags']) . "] {$r['category']} +" . implode(',', $r['suggested']) . " [$src" . ($r['api'] ? ',api' : '') . '] ' . round(microtime(true) - $t0, 1) . "s\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $status = $attempts >= $max ? 'error' : 'pending';
        try { $pdo->prepare('UPDATE bookmarks SET status=?, error=? WHERE id=?')->execute([$status, mb_substr($e->getMessage(), 0, 500), $id]); } catch (Throwable $e2) { error_log('worker: ' . $e2->getMessage()); }
        echo date('c') . " fail #$id {$b['url']} attempt $attempts/$max: {$e->getMessage()}\n";
    }
}

// Deferred OpenRouter step (rate limit / outage earlier): up to 5 due bookmarks per run, with backoff
$due = $pdo->prepare("SELECT * FROM bookmarks WHERE llm_pending=1 AND llm_next_at<=? AND status='done' ORDER BY llm_next_at LIMIT 5");
$due->execute([time()]);
foreach ($due->fetchAll() as $b) {
    try { $r = llm_retry($pdo, $b, vocab($pdo, (int)$b['user_id'])); } catch (Throwable $e) { $r = 'error: ' . $e->getMessage(); }
    echo date('c') . " llm #{$b['id']} {$b['url']} -> $r\n";
}

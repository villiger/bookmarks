#!/usr/bin/env php
<?php
// Cron: * * * * * flock-protected; processes pending bookmarks one at a time.
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/crawler.php';
require_once __DIR__ . '/../src/tagger.php';

$lock = fopen(cfg('lock_path'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);   // another worker is running

$pdo = db();
$max = (int)(cfg('max_attempts') ?: 3);
$only = $argv[1] ?? null;   // optional: bin/worker.php <id> to force a single bookmark

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
        $c['source'] = $src;
        // Final URL after redirects, cleaned; merge into an existing bookmark if it's a duplicate
        $finalUrl = clean_url($c['final_url'] ?? $b['url']);
        $finalKey = url_key($finalUrl);
        $dup = $pdo->prepare('SELECT id FROM bookmarks WHERE user_id=? AND url_key=? AND id<>?');
        $dup->execute([$b['user_id'], $finalKey, $id]);
        if ($dup->fetchColumn()) { delete_bookmark($id, (int)$b['user_id']); echo date('c') . " merged #$id into existing {$finalUrl}\n"; continue; }
        $title = $c['title'] ?: $b['title'] ?: $b['url'];
        $desc = $c['description'];
        $vocab = vocab($pdo, (int)$b['user_id']);
        $r = tag_bookmark($finalUrl, $c, $vocab, $pdo, (int)$b['user_id']);
        if ($desc === '') $desc = $r['summary'] ?: mb_substr($c['body'], 0, 200);
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE bookmarks SET title=?, description=?, favicon=?, image=?, body=?, category=?, status='done', error=NULL, crawl_source=?, embedding=?, url=?, url_key=?, scheme_guessed=0 WHERE id=?")
            ->execute([$title, $desc, $c['favicon'], $c['image'], $c['body'], $r['category'], $src, $r['embedding'], $finalUrl, $finalKey, $id]);
        if (!$b['tags_edited']) {      // never overwrite tags the user corrected
            $pdo->prepare('DELETE FROM tags WHERE bookmark_id=?')->execute([$id]);
            $ins = $pdo->prepare('INSERT OR IGNORE INTO tags(bookmark_id, tag, suggested) VALUES(?,?,?)');
            foreach ($r['tags'] as $t) $ins->execute([$id, $t, 0]);
            foreach ($r['suggested'] as $t) $ins->execute([$id, $t, 1]);
        }
        if ($r['llm_pending'] && !$b['tags_edited']) $pdo->prepare('UPDATE bookmarks SET llm_pending=1, llm_attempts=1, llm_next_at=? WHERE id=?')->execute([time() + llm_backoff(1), $id]);
        else $pdo->prepare('UPDATE bookmarks SET llm_pending=0 WHERE id=?')->execute([$id]);
        $pdo->commit();
        fts_reindex($id);
        echo date('c') . " done #$id {$b['url']} [" . implode(',', $r['tags']) . "] {$r['category']} +" . implode(',', $r['suggested']) . " [$src" . ($r['api'] ? ',api' : '') . '] ' . round(microtime(true) - $t0, 1) . "s\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $status = $attempts >= $max ? 'error' : 'pending';
        $pdo->prepare('UPDATE bookmarks SET status=?, error=? WHERE id=?')->execute([$status, mb_substr($e->getMessage(), 0, 500), $id]);
        echo date('c') . " fail #$id {$b['url']} attempt $attempts/$max: {$e->getMessage()}\n";
    }
}

// Deferred OpenRouter step: up to 5 due bookmarks per run (local tags are already in place)
$due = $pdo->prepare("SELECT * FROM bookmarks WHERE llm_pending=1 AND tags_edited=0 AND llm_next_at<=? AND status='done' ORDER BY llm_next_at LIMIT 5");
$due->execute([time()]);
foreach ($due->fetchAll() as $b) {
    try { $res = llm_retry($pdo, $b, vocab($pdo, (int)$b['user_id'])); } catch (Throwable $e) { $res = 'error: ' . $e->getMessage(); }
    echo date('c') . " llm #{$b['id']} {$b['url']} -> $res\n";
}

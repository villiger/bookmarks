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
        $c = crawl($b['url']);
        $title = $c['title'] ?: $b['title'] ?: $b['url'];
        $desc = $c['description'];
        $note = null;
        $existing = known_tags($pdo);
        $yt = youtube_id($b['url']) !== null;
        try {
            $r = match_known_tags($existing, $c['keywords'], $title, $desc, $yt);
            $viaLlm = $r === null;
            if ($viaLlm) $r = llm_tag($title, $desc, $c['body'], $existing);
            if ($viaLlm && $desc === '' && trim($c['body']) !== '') {
                try { $desc = llm_summary($title, $c['body']); } catch (Throwable $e) { $note = 'summary: ' . $e->getMessage(); }
            }
        } catch (Throwable $e) {
            // LLM failed: keep the crawl result, fall back to meta keywords
            $kw = array_filter(array_map('trim', explode(',', $c['keywords'])));
            $r = ['tags' => normalize_tags($kw), 'category' => youtube_id($b['url']) ? 'video' : 'other'];
            $note = 'llm: ' . $e->getMessage();
        }
        if (youtube_id($b['url'])) $r['category'] = 'video';
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE bookmarks SET title=?, description=?, favicon=?, image=?, body=?, category=?, status='done', error=? WHERE id=?")
            ->execute([$title, $desc, $c['favicon'], $c['image'], $c['body'], $r['category'], $note ? mb_substr($note, 0, 500) : null, $id]);
        $pdo->prepare('DELETE FROM tags WHERE bookmark_id=?')->execute([$id]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO tags(bookmark_id, tag) VALUES(?,?)');
        foreach ($r['tags'] as $t) $ins->execute([$id, $t]);
        $pdo->commit();
        fts_reindex($id);
        echo date('c') . " done #$id {$b['url']} [" . implode(',', $r['tags']) . "] {$r['category']} " . (($viaLlm ?? true) ? '' : '(no-llm) ') . round(microtime(true) - $t0, 1) . "s" . ($note ? " ($note)" : '') . "\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $status = $attempts >= $max ? 'error' : 'pending';
        $pdo->prepare('UPDATE bookmarks SET status=?, error=? WHERE id=?')->execute([$status, mb_substr($e->getMessage(), 0, 500), $id]);
        echo date('c') . " fail #$id {$b['url']} attempt $attempts/$max: {$e->getMessage()}\n";
    }
}

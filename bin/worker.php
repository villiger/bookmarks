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
        $c = crawl_any(crawl_candidates($b['url'], (bool)$b['scheme_guessed']));
        // Final URL after redirects, cleaned; merge into an existing bookmark if it's a duplicate
        $finalUrl = clean_url($c['final_url'] ?? $b['url']);
        $finalKey = url_key($finalUrl);
        $dup = $pdo->prepare('SELECT id FROM bookmarks WHERE user_id=? AND url_key=? AND id<>?');
        $dup->execute([$b['user_id'], $finalKey, $id]);
        if ($dup->fetchColumn()) { delete_bookmark($id, (int)$b['user_id']); echo date('c') . " merged #$id into existing {$finalUrl}\n"; continue; }
        $title = $c['title'] ?: $b['title'] ?: $b['url'];
        $desc = $c['description'];
        $note = null;
        $known = known_tags($pdo, (int)$b['user_id']);
        $yt = youtube_id($finalUrl) !== null;
        $viaLlm = false;
        try {
            $r = tag_bookmark($finalUrl, $c, $known, $pdo, (int)$b['user_id']);
            $viaLlm = $r['llm'];
            if ($viaLlm && $desc === '' && trim($c['body']) !== '') {
                try { $desc = llm_summary($title, $c['body']); } catch (Throwable $e) { $note = 'summary: ' . $e->getMessage(); }
            }
        } catch (Throwable $e) {
            // LLM failed: keep the crawl result, fall back to rules/meta keywords
            $dr = apply_domain_rules($finalUrl);
            $kw = array_filter(array_map('trim', explode(',', $c['keywords'])));
            $r = ['tags' => array_slice(array_values(array_unique(array_merge($dr['tags'], array_intersect(normalize_tags($kw), $known)))), 0, 4), 'suggested' => [], 'category' => $dr['category'] ?? ($yt ? 'video' : 'other')];
            $note = 'llm: ' . $e->getMessage();
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE bookmarks SET title=?, description=?, favicon=?, image=?, body=?, category=?, status='done', error=?, url=?, url_key=?, scheme_guessed=0 WHERE id=?")
            ->execute([$title, $desc, $c['favicon'], $c['image'], $c['body'], $r['category'], $note ? mb_substr($note, 0, 500) : null, $finalUrl, $finalKey, $id]);
        $pdo->prepare('DELETE FROM tags WHERE bookmark_id=?')->execute([$id]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO tags(bookmark_id, tag, suggested) VALUES(?,?,?)');
        foreach ($r['tags'] as $t) $ins->execute([$id, $t, 0]);
        foreach ($r['suggested'] as $t) $ins->execute([$id, $t, 1]);
        $pdo->commit();
        fts_reindex($id);
        echo date('c') . " done #$id {$b['url']} [" . implode(',', $r['tags']) . "] {$r['category']} " . ($viaLlm ? '' : '(no-llm) ') . round(microtime(true) - $t0, 1) . "s" . ($note ? " ($note)" : '') . "\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $status = $attempts >= $max ? 'error' : 'pending';
        $pdo->prepare('UPDATE bookmarks SET status=?, error=? WHERE id=?')->execute([$status, mb_substr($e->getMessage(), 0, 500), $id]);
        echo date('c') . " fail #$id {$b['url']} attempt $attempts/$max: {$e->getMessage()}\n";
    }
}

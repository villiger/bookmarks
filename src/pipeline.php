<?php
// Background processing of bookmarks (run by bin/worker.php): crawl, clean, tag, store; then the deferred OpenRouter retries.
require_once __DIR__ . '/bookmarks.php';
require_once __DIR__ . '/crawler.php';
require_once __DIR__ . '/tagger.php';

/** Pending bookmarks that ran out of attempts (e.g. a killed run) become errors, so no card stays 'pending' forever. */
function fail_exhausted(int $max): void {
    q("UPDATE jobs SET status='error', error='Gave up: processing was interrupted or failed ' || attempts || ' times' WHERE status='pending' AND attempts >= ?", [$max]);
}

/** Oldest pending bookmark with its job state and the data the bookmarklet sent, or null. */
function next_pending(int $max): ?array {
    return q("SELECT b.*, " . JOB_COLS . ', ' . CAPTURE_COLS . ' FROM ' . BM_FROM . " LEFT JOIN capture c ON c.bookmark_id=b.id WHERE j.status='pending' AND j.attempts < ? AND " . BM_LIVE . ' ORDER BY b.id LIMIT 1', [$max])->fetch() ?: null;
}

/** Crawl and tag one bookmark; a failure is recorded on its job. @return string log line */
function process_bookmark(array $b, int $max): string {
    $pdo = db();
    $id = (int)$b['id'];
    q('UPDATE jobs SET attempts = attempts + 1 WHERE bookmark_id=?', [$id]);
    $attempts = (int)$b['attempts'] + 1;
    $t0 = microtime(true);
    try {
        [$c, $src] = crawl_resilient($b);
        // Final URL after redirects, cleaned; merge into an existing bookmark if it's a duplicate
        $finalUrl = clean_url($c['final_url'] ?? $b['url']);
        $finalKey = url_key($finalUrl);
        if (url_taken((int)$b['user_id'], $finalKey, $id)) { delete_bookmark($id, (int)$b['user_id']); return "merged #$id into existing $finalUrl"; }
        $titleOrig = $c['title'] ?: $b['title'] ?: $b['url'];
        $title = clean_title($titleOrig, $finalUrl, (string)$c['site_name']);
        $r = tag_bookmark($finalUrl, $c, vocab($pdo, (int)$b['user_id']), $pdo, (int)$b['user_id']);
        if (!empty($r['title'])) $title = $r['title'];   // the model replaced a bad crawled title (the original stays in title_orig)
        $pdo->beginTransaction();
        q("UPDATE bookmarks SET title=CASE WHEN text_edited=1 THEN title ELSE ? END, title_orig=?, description=?, summary=CASE WHEN text_edited=1 THEN summary ELSE ? END, favicon=?, image=?, body=?, category=?, crawl_source=?, url=?, url_key=?, scheme_guessed=0 WHERE id=?",
            [$title, $titleOrig, $c['description'], $r['summary'], $c['favicon'], $c['image'], $c['body'], $r['category'], $src, $finalUrl, $finalKey, $id]);
        save_tags($id, $r, $b);
        $llm = (int)$r['llm_pending'];
        q("UPDATE jobs SET status='done', error=NULL, llm_pending=?, llm_attempts=?, llm_next_at=?, refresh=? WHERE bookmark_id=?",
            [$llm, $llm, time() + ($llm ? llm_backoff(1) : 0), (int)($llm && $b['refresh']), $id]);
        $pdo->commit();
        fts_reindex($id);
        return "done #$id {$b['url']} [" . implode(',', $r['tags']) . "] {$r['category']} +" . implode(',', $r['suggested']) . " [$src" . ($r['api'] ? ',api' : '') . '] ' . round(microtime(true) - $t0, 1) . 's';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try { q('UPDATE jobs SET status=?, error=? WHERE bookmark_id=?', [$attempts >= $max ? 'error' : 'pending', mb_substr($e->getMessage(), 0, 500), $id]); } catch (Throwable $e2) { error_log('worker: ' . $e2->getMessage()); }
        return "fail #$id {$b['url']} attempt $attempts/$max: {$e->getMessage()}";
    }
}

/** Tags are replaced on every re-process, except for bookmarks the user corrected: those are untouched, or (refresh) keep their applied tags and get the new ones on top. */
function save_tags(int $id, array $r, array $b): void {
    if ($b['tags_edited'] && !$b['refresh']) return;
    $cur = $b['tags_edited'] ? q('SELECT tag FROM tags WHERE bookmark_id=? AND suggested=0', [$id])->fetchAll(PDO::FETCH_COLUMN) : [];
    q('DELETE FROM tags WHERE bookmark_id=?', [$id]);
    $ins = db()->prepare('INSERT OR IGNORE INTO tags(bookmark_id, tag, suggested) VALUES(?,?,?)');
    foreach (array_merge($r['tags'], $cur) as $t) $ins->execute([$id, $t, 0]);
    foreach (array_diff($r['suggested'], $cur) as $t) $ins->execute([$id, $t, 1]);
}

/** Deferred OpenRouter step (rate limit / outage earlier): up to 5 due bookmarks per run, with backoff. @return string[] log lines */
function retry_deferred_llm(): array {
    $pdo = db();
    $due = q('SELECT b.*, ' . JOB_COLS . ' FROM ' . BM_FROM . " WHERE j.llm_pending=1 AND j.llm_next_at<=? AND j.status='done' AND " . BM_LIVE . ' ORDER BY j.llm_next_at LIMIT 5', [time()])->fetchAll();
    $log = [];
    foreach ($due as $b) {
        try { $r = llm_retry($pdo, $b, vocab($pdo, (int)$b['user_id'])); } catch (Throwable $e) { $r = 'error: ' . $e->getMessage(); }
        $log[] = "llm #{$b['id']} {$b['url']} -> $r";
    }
    return $log;
}

<?php
// Bookmark queries: add, trash, search, cards, tag lists. Rows come from `bookmarks` joined with their `jobs` row (processing state).
require_once __DIR__ . '/db.php';

const TRASH_DAYS = 30;
const JOB_COLS = 'j.status, j.error, j.attempts, j.refresh, j.llm_pending, j.llm_attempts, j.llm_next_at';
const CAPTURE_COLS = 'c.client_title, c.client_desc, c.client_image, c.client_favicon, c.client_canonical, c.client_keywords, c.client_text';
const BM_FROM = 'bookmarks b JOIN jobs j ON j.bookmark_id = b.id';
const BM_LIVE = 'b.deleted_at IS NULL';

/** Rewrite the FTS row of one bookmark (title, description, body, tags, url); trashed or missing bookmarks get no row. */
function fts_reindex(int $id): void {
    q('DELETE FROM bookmarks_fts WHERE rowid=?', [$id]);
    $row = q('SELECT * FROM bookmarks WHERE id=? AND deleted_at IS NULL', [$id])->fetch();
    if (!$row) return;
    $tags = q('SELECT tag FROM tags WHERE bookmark_id=? AND suggested=0 ORDER BY tag', [$id])->fetchAll(PDO::FETCH_COLUMN);
    if ($row['category']) $tags[] = $row['category'];
    q('INSERT INTO bookmarks_fts(rowid,title,description,body,tags,url) VALUES(?,?,?,?,?,?)',
        [$id, $row['title'], trim($row['description'] . ' ' . $row['summary']), $row['body'], implode(' ', $tags), $row['url']]);
}

/** Put a bookmark back in the queue: re-crawl and re-tag. Applied tags of a user-corrected bookmark stay (refresh), new ones are added. */
function requeue(int $id): void {
    q("UPDATE jobs SET status='pending', attempts=0, error=NULL, refresh=(SELECT tags_edited FROM bookmarks WHERE id=?1), llm_pending=0, llm_attempts=0 WHERE bookmark_id=?1", [$id]);
}

function save_capture(int $id, array $cv): void {
    q('INSERT OR REPLACE INTO capture(bookmark_id, ' . implode(',', array_keys($cv)) . ') VALUES(?' . str_repeat(',?', count($cv)) . ')', array_merge([$id], array_values($cv)));
}

/** Insert for a user. @return array{status:string,id?:int,error?:string} */
function add_bookmark(int $uid, string $raw, string $title = '', array $client = []): array {
    $n = normalize_input($raw);
    if (isset($n['error'])) return ['status' => 'invalid', 'error' => $n['error']];
    $key = url_key($n['url']);
    $max = ['client_title' => 300, 'client_desc' => 600, 'client_image' => 1000, 'client_favicon' => 1000, 'client_canonical' => 1000, 'client_keywords' => 300, 'client_text' => 1500];
    $cv = [];
    foreach ($max as $c => $len) $cv[$c] = mb_substr(trim((string)($client[$c] ?? '')), 0, $len);
    q('DELETE FROM bookmarks WHERE user_id=? AND url_key=? AND deleted_at IS NOT NULL', [$uid, $key]);   // saving a URL that sits in the trash starts fresh
    $st = q('INSERT OR IGNORE INTO bookmarks(user_id,url,url_key,scheme_guessed,title) VALUES(?,?,?,?,?)', [$uid, $n['url'], $key, $n['guessed'] ? 1 : 0, mb_substr($title, 0, 300)]);
    if ($st->rowCount()) {
        $id = (int)db()->lastInsertId();
        if (array_filter($cv)) save_capture($id, $cv);
        fts_reindex($id);
        return ['status' => 'added', 'id' => $id];
    }
    $id = (int)q('SELECT id FROM bookmarks WHERE user_id=? AND url_key=?', [$uid, $key])->fetchColumn();
    if (array_filter($cv)) {    // re-saving from the browser: keep the richer page data and re-process
        save_capture($id, $cv);
        q("UPDATE jobs SET status='pending', attempts=0, error=NULL WHERE bookmark_id=?", [$id]);
    } else {
        q("UPDATE jobs SET status='pending', attempts=0, error=NULL WHERE bookmark_id=? AND status='error'", [$id]);
    }
    return ['status' => 'dup', 'error' => 'Already saved: ' . $n['url']];
}

/** Remove a bookmark for good (tags, job and capture go with it). */
function delete_bookmark(int $id, int $uid): void {
    if (q('DELETE FROM bookmarks WHERE id=? AND user_id=?', [$id, $uid])->rowCount()) q('DELETE FROM bookmarks_fts WHERE rowid=?', [$id]);
}

/** Move to the trash: hidden everywhere, restorable for TRASH_DAYS. @return bool whether something was trashed */
function trash_bookmark(int $id, int $uid): bool {
    q('DELETE FROM bookmarks WHERE deleted_at < ?', [time() - 86400 * TRASH_DAYS]);
    if (!q('UPDATE bookmarks SET deleted_at=? WHERE id=? AND user_id=? AND deleted_at IS NULL', [time(), $id, $uid])->rowCount()) return false;
    fts_reindex($id);
    return true;
}

/** Bring a trashed bookmark back. @return int|null its id, null if it is gone */
function restore_bookmark(int $id, int $uid): ?int {
    if (!q('UPDATE bookmarks SET deleted_at=NULL WHERE id=? AND user_id=? AND deleted_at IS NOT NULL', [$id, $uid])->rowCount()) return null;
    fts_reindex($id);
    return $id;
}

/** Delete a trashed bookmark forever. */
function purge_bookmark(int $id, int $uid): void {
    q('DELETE FROM bookmarks WHERE id=? AND user_id=? AND deleted_at IS NOT NULL', [$id, $uid]);
}

/** The user's trash, newest first: id, title, url, deleted_at. */
function trash_list(int $uid): array {
    return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => (string)($r['title'] ?: $r['url']), 'url' => (string)$r['url'], 'deleted_at' => (int)$r['deleted_at']],
        q('SELECT id, title, url, deleted_at FROM bookmarks WHERE user_id=? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC', [$uid])->fetchAll());
}

function top_tags(int $uid, int $limit = 40, int $min = 1, array $exclude = []): array {
    $not = $exclude ? ' AND t.tag NOT IN (' . implode(',', array_map(fn($t) => db()->quote($t), $exclude)) . ')' : '';
    return q('SELECT t.tag, COUNT(*) c FROM tags t JOIN bookmarks b ON b.id=t.bookmark_id WHERE b.user_id=? AND ' . BM_LIVE . " AND t.suggested=0$not GROUP BY t.tag HAVING c>=" . (int)$min . ' ORDER BY c DESC, t.tag LIMIT ' . (int)$limit, [$uid])->fetchAll();
}

/** Tags applied to at least $min of the user's bookmarks: only these are offered as suggestions on cards. */
function established_tags(int $uid, int $min = 3): array {
    return array_flip(q('SELECT t.tag FROM tags t JOIN bookmarks b ON b.id=t.bookmark_id WHERE b.user_id=? AND ' . BM_LIVE . ' AND t.suggested=0 GROUP BY t.tag HAVING COUNT(*)>=' . (int)$min, [$uid])->fetchAll(PDO::FETCH_COLUMN));
}

/** Turn user input into a safe FTS5 query: every word is a quoted prefix term (AND). */
function fts_query(string $q): string {
    preg_match_all('/[\p{L}\p{N}]+/u', $q, $m);
    return implode(' ', array_map(fn($t) => '"' . $t . '"*', array_slice($m[0], 0, 8)));
}

/** ORDER BY for a sort mode ('' = shuffle, or best match when searching). The shuffle is a seeded hash, so it stays stable while the page is open. */
function sort_sql(string $sort, int $seed, bool $searching): string {
    if ($sort === '' && $searching) $sort = 'match';
    return match ($sort) {
        'new' => 'b.created_at DESC, b.id DESC',
        'old' => 'b.created_at ASC, b.id ASC',
        'az' => 'b.title COLLATE NOCASE ASC',
        'review' => "(j.status='error') DESC, (j.status='pending') DESC, (SELECT COUNT(*) FROM tags WHERE bookmark_id=b.id AND suggested=0) ASC, (COALESCE(b.summary,'')='') DESC, b.id DESC",
        'match' => 'bm25(bookmarks_fts, 10.0, 4.0, 1.0, 6.0, 0.5)',
        default => '((b.id + ' . $seed . ') * 2654435761) % 4294967291',
    };
}

/** Attach 'tags' and 'suggested' (only established tags) to card rows. */
function with_tags(array $rows, int $uid): array {
    if (!$rows) return $rows;
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    $tags = []; $sugg = [];
    foreach (db()->query("SELECT bookmark_id, tag, suggested FROM tags WHERE bookmark_id IN ($ids) ORDER BY tag") as $t) {
        if ($t['suggested']) $sugg[$t['bookmark_id']][] = $t['tag']; else $tags[$t['bookmark_id']][] = $t['tag'];
    }
    $ok = established_tags($uid);
    foreach ($rows as &$r) { $r['tags'] = $tags[$r['id']] ?? []; $r['suggested'] = array_values(array_filter($sugg[$r['id']] ?? [], fn($t) => isset($ok[$t]))); }
    return $rows;
}

function search_bookmarks(int $uid, string $q, string $tag, int $limit = 100, string $sort = '', int $seed = 0): array {
    $params = [':uid' => $uid];
    $tagSql = '';
    if ($tag !== '') { $tagSql = ' AND b.id IN (SELECT bookmark_id FROM tags WHERE tag = :tag)'; $params[':tag'] = $tag; }
    $fq = fts_query($q);
    if ($fq !== '') {
        $params[':q'] = $fq;
        $sql = 'SELECT b.*, ' . JOB_COLS . ",
                  highlight(bookmarks_fts,0,char(2),char(3)) AS h_title,
                  snippet(bookmarks_fts,2,char(2),char(3),'…',14) AS h_body,
                  highlight(bookmarks_fts,1,char(2),char(3)) AS h_desc
                FROM bookmarks_fts JOIN bookmarks b ON b.id = bookmarks_fts.rowid JOIN jobs j ON j.bookmark_id = b.id
                WHERE bookmarks_fts MATCH :q AND b.user_id = :uid AND " . BM_LIVE . " $tagSql
                ORDER BY " . sort_sql($sort, $seed, true) . " LIMIT $limit";
    } else {
        $sql = 'SELECT b.*, ' . JOB_COLS . ', NULL h_title, NULL h_body, NULL h_desc FROM ' . BM_FROM . ' WHERE b.user_id = :uid AND ' . BM_LIVE . " $tagSql
                ORDER BY " . sort_sql($sort === 'match' ? '' : $sort, $seed, false) . " LIMIT $limit";
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return with_tags($st->fetchAll(), $uid);
}

/** One live bookmark of this user as a card row (no highlights), or null. */
function fetch_card(int $id, int $uid): ?array {
    $r = q('SELECT b.*, ' . JOB_COLS . ', NULL h_title, NULL h_body, NULL h_desc FROM ' . BM_FROM . ' WHERE b.id=? AND b.user_id=? AND ' . BM_LIVE, [$id, $uid])->fetch();
    return $r ? with_tags([$r], $uid)[0] : null;
}

/** Is this normalized URL already saved by the user as another live bookmark? A trashed copy is dropped to make room (so restoring it can never clash). */
function url_taken(int $uid, string $key, int $exceptId): bool {
    q('DELETE FROM bookmarks WHERE user_id=? AND url_key=? AND id<>? AND deleted_at IS NOT NULL', [$uid, $key, $exceptId]);
    return (bool)q('SELECT 1 FROM bookmarks WHERE user_id=? AND url_key=? AND id<>?', [$uid, $key, $exceptId])->fetchColumn();
}

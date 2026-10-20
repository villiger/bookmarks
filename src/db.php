<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/url.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = cfg('db_path');
    @mkdir(dirname($path), 0770, true);
    $new = !is_file($path);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=30000; PRAGMA foreign_keys=ON; PRAGMA synchronous=NORMAL;');
    $has = $pdo->query("SELECT 1 FROM sqlite_master WHERE name='bookmarks_fts'")->fetchColumn();
    if ($new || !$has) $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
    // Migrations for databases created before these columns existed
    $has = fn($t, $c) => (bool)array_filter($pdo->query("PRAGMA table_info($t)")->fetchAll(), fn($r) => $r['name'] === $c);
    if (!$has('bookmarks', 'tags_edited')) $pdo->exec('ALTER TABLE bookmarks ADD COLUMN tags_edited INTEGER NOT NULL DEFAULT 0');
    if (!$has('tags', 'suggested')) $pdo->exec('ALTER TABLE tags ADD COLUMN suggested INTEGER NOT NULL DEFAULT 0');
    if (!$has('bookmarks', 'url_key')) $pdo->exec("ALTER TABLE bookmarks ADD COLUMN url_key TEXT NOT NULL DEFAULT ''");
    if (!$has('bookmarks', 'scheme_guessed')) $pdo->exec('ALTER TABLE bookmarks ADD COLUMN scheme_guessed INTEGER NOT NULL DEFAULT 0');
    foreach (['title_orig', 'summary', 'client_title', 'client_desc', 'client_image', 'client_favicon', 'client_canonical', 'client_keywords', 'client_text', 'crawl_source'] as $col) {
        if (!$has('bookmarks', $col)) $pdo->exec("ALTER TABLE bookmarks ADD COLUMN $col TEXT");
    }
    foreach (['llm_pending', 'llm_attempts', 'llm_next_at', 'refresh', 'text_edited'] as $col) {
        if (!$has('bookmarks', $col)) $pdo->exec("ALTER TABLE bookmarks ADD COLUMN $col INTEGER NOT NULL DEFAULT 0");
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS api_model_usage (month TEXT NOT NULL, model TEXT NOT NULL, calls INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (month, model))');
    $pdo->exec("CREATE TABLE IF NOT EXISTS trash (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, data TEXT NOT NULL, deleted_at INTEGER NOT NULL DEFAULT (strftime('%s','now')))");
    $pdo->exec('CREATE TABLE IF NOT EXISTS api_usage (month TEXT PRIMARY KEY, calls INTEGER NOT NULL DEFAULT 0)');
    foreach ($pdo->query("SELECT id, url FROM bookmarks WHERE url_key=''")->fetchAll() as $r) {
        $pdo->prepare('UPDATE bookmarks SET url_key=? WHERE id=?')->execute([url_key($r['url']), $r['id']]);
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_bookmarks_urlkey ON bookmarks(user_id, url_key)');
    @chmod($path, 0660);
    return $pdo;
}

/** Rewrite the FTS row of one bookmark (title, description, body, tags, url). */
function fts_reindex(int $id): void {
    $pdo = db();
    $b = $pdo->prepare('SELECT * FROM bookmarks WHERE id=?');
    $b->execute([$id]);
    $row = $b->fetch();
    $pdo->prepare('DELETE FROM bookmarks_fts WHERE rowid=?')->execute([$id]);
    if (!$row) return;
    $t = $pdo->prepare('SELECT tag FROM tags WHERE bookmark_id=? AND suggested=0 ORDER BY tag');
    $t->execute([$id]);
    $tags = $t->fetchAll(PDO::FETCH_COLUMN);
    if ($row['category']) $tags[] = $row['category'];
    $pdo->prepare('INSERT INTO bookmarks_fts(rowid,title,description,body,tags,url) VALUES(?,?,?,?,?,?)')
        ->execute([$id, $row['title'], trim($row['description'] . ' ' . $row['summary']), $row['body'], implode(' ', $tags), $row['url']]);
}

const TRASH_DAYS = 30;

/** Delete a bookmark but keep a snapshot (row + tags) for TRASH_DAYS so it can be restored. @return bool whether something was deleted */
function trash_bookmark(int $id, int $uid): bool {
    $pdo = db();
    $pdo->exec('DELETE FROM trash WHERE deleted_at < ' . (time() - 86400 * TRASH_DAYS));
    $st = $pdo->prepare('SELECT * FROM bookmarks WHERE id=? AND user_id=?'); $st->execute([$id, $uid]);
    if (!($row = $st->fetch(PDO::FETCH_ASSOC))) return false;
    $t = $pdo->prepare('SELECT tag, suggested FROM tags WHERE bookmark_id=?'); $t->execute([$id]);
    $pdo->prepare('INSERT OR REPLACE INTO trash(id, user_id, data) VALUES(?,?,?)')->execute([$id, $uid, json_encode(['row' => $row, 'tags' => $t->fetchAll(PDO::FETCH_ASSOC)], JSON_INVALID_UTF8_SUBSTITUTE)]);
    delete_bookmark($id, $uid);
    return true;
}

/** Put a trashed bookmark back (same id when free). @return int|null the bookmark id, null if it is gone or its URL was saved again meanwhile */
function restore_bookmark(int $id, int $uid): ?int {
    $pdo = db();
    $st = $pdo->prepare('SELECT data FROM trash WHERE id=? AND user_id=?'); $st->execute([$id, $uid]);
    if (!($d = json_decode((string)$st->fetchColumn(), true))) return null;
    $row = $d['row'];
    $taken = $pdo->prepare('SELECT 1 FROM bookmarks WHERE id=?'); $taken->execute([$id]);
    if ($taken->fetchColumn()) unset($row['id']);
    $cols = array_keys($row);
    $ins = $pdo->prepare('INSERT OR IGNORE INTO bookmarks(' . implode(',', $cols) . ') VALUES(' . implode(',', array_fill(0, count($cols), '?')) . ')');
    $ins->execute(array_values($row));
    if (!$ins->rowCount()) return null;
    $new = (int)$pdo->lastInsertId();
    $ti = $pdo->prepare('INSERT OR IGNORE INTO tags(bookmark_id, tag, suggested) VALUES(?,?,?)');
    foreach ($d['tags'] as $t) $ti->execute([$new, $t['tag'], $t['suggested']]);
    $pdo->prepare('DELETE FROM trash WHERE id=?')->execute([$id]);
    fts_reindex($new);
    return $new;
}

function delete_bookmark(int $id, int $uid): void {
    $pdo = db();
    $o = $pdo->prepare('SELECT 1 FROM bookmarks WHERE id=? AND user_id=?'); $o->execute([$id, $uid]);
    if (!$o->fetchColumn()) return;
    $pdo->prepare('DELETE FROM bookmarks_fts WHERE rowid=?')->execute([$id]);
    $pdo->prepare('DELETE FROM tags WHERE bookmark_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM bookmarks WHERE id=?')->execute([$id]);
}

function top_tags(int $uid, int $limit = 40, int $min = 1, array $exclude = []): array {
    $not = $exclude ? ' AND t.tag NOT IN (' . implode(',', array_map(fn($t) => db()->quote($t), $exclude)) . ')' : '';
    $st = db()->prepare("SELECT t.tag, COUNT(*) c FROM tags t JOIN bookmarks b ON b.id=t.bookmark_id WHERE b.user_id=? AND t.suggested=0$not GROUP BY t.tag HAVING c>=" . (int)$min . " ORDER BY c DESC, t.tag LIMIT " . (int)$limit);
    $st->execute([$uid]);
    return $st->fetchAll();
}

/** Turn user input into a safe FTS5 query: every word is a quoted prefix term (AND). */
function fts_query(string $q): string {
    preg_match_all('/[\p{L}\p{N}]+/u', $q, $m);
    $terms = array_slice($m[0], 0, 8);
    return implode(' ', array_map(fn($t) => '"' . $t . '"*', $terms));
}

/** ORDER BY for a sort mode ('' = shuffle, or best match when searching). The shuffle is a seeded hash, so it stays stable while the page is open. */
function sort_sql(string $sort, int $seed, bool $searching): string {
    if ($sort === '' && $searching) $sort = 'match';
    return match ($sort) {
        'new' => 'b.created_at DESC, b.id DESC',
        'old' => 'b.created_at ASC, b.id ASC',
        'az' => 'b.title COLLATE NOCASE ASC',
        'review' => "(b.status='error') DESC, (b.status='pending') DESC, (SELECT COUNT(*) FROM tags WHERE bookmark_id=b.id AND suggested=0) ASC, (COALESCE(b.summary,'')='') DESC, b.id DESC",
        'match' => 'bm25(bookmarks_fts, 10.0, 4.0, 1.0, 6.0, 0.5)',
        default => '((b.id + ' . $seed . ') * 2654435761) % 4294967291',
    };
}

function search_bookmarks(int $uid, string $q, string $tag, int $limit = 100, string $sort = '', int $seed = 0): array {
    $pdo = db();
    $params = [':uid' => $uid];
    $tagSql = '';
    if ($tag !== '') { $tagSql = ' AND b.id IN (SELECT bookmark_id FROM tags WHERE tag = :tag)'; $params[':tag'] = $tag; }
    $fq = fts_query($q);
    if ($fq !== '') {
        $params[':q'] = $fq;
        $sql = "SELECT b.*,
                  highlight(bookmarks_fts,0,char(2),char(3)) AS h_title,
                  snippet(bookmarks_fts,2,char(2),char(3),'…',14) AS h_body,
                  highlight(bookmarks_fts,1,char(2),char(3)) AS h_desc
                FROM bookmarks_fts JOIN bookmarks b ON b.id = bookmarks_fts.rowid
                WHERE bookmarks_fts MATCH :q AND b.user_id = :uid $tagSql
                ORDER BY " . sort_sql($sort, $seed, true) . " LIMIT $limit";
    } else {
        $sql = "SELECT b.*, NULL h_title, NULL h_body, NULL h_desc FROM bookmarks b WHERE b.user_id = :uid $tagSql
                ORDER BY " . sort_sql($sort === 'match' ? '' : $sort, $seed, false) . " LIMIT $limit";
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    if ($rows) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $tags = []; $sugg = [];
        foreach ($pdo->query("SELECT bookmark_id, tag, suggested FROM tags WHERE bookmark_id IN ($ids) ORDER BY tag") as $t) {
            if ($t['suggested']) $sugg[$t['bookmark_id']][] = $t['tag']; else $tags[$t['bookmark_id']][] = $t['tag'];
        }
        $ok = established_tags($uid);
        foreach ($rows as &$r) { $r['tags'] = $tags[$r['id']] ?? []; $r['suggested'] = array_values(array_filter($sugg[$r['id']] ?? [], fn($t) => isset($ok[$t]))); }
    }
    return $rows;
}

/** Tags applied to at least $min of the user's bookmarks: only these are offered as suggestions on cards. */
function established_tags(int $uid, int $min = 3): array {
    $st = db()->prepare('SELECT t.tag FROM tags t JOIN bookmarks b ON b.id=t.bookmark_id WHERE b.user_id=? AND t.suggested=0 GROUP BY t.tag HAVING COUNT(*)>=' . (int)$min);
    $st->execute([$uid]);
    return array_flip($st->fetchAll(PDO::FETCH_COLUMN));
}

/** Vocabulary offered to the user for autocomplete: seed tags + their own tags. */
function user_vocabulary(int $uid): array {
    require_once __DIR__ . '/tagger.php';
    return array_keys(vocab(db(), $uid, 200, true));
}

/** One bookmark of this user as a card row (no highlights), or null. */
function fetch_card(int $id, int $uid): ?array {
    $st = db()->prepare('SELECT b.*, NULL h_title, NULL h_body, NULL h_desc FROM bookmarks b WHERE b.id=? AND b.user_id=?');
    $st->execute([$id, $uid]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['tags'] = []; $r['suggested'] = [];
    $t = db()->prepare('SELECT tag, suggested FROM tags WHERE bookmark_id=? ORDER BY tag');
    $t->execute([$id]);
    $ok = established_tags($uid);
    foreach ($t as $x) { if (!$x['suggested']) $r['tags'][] = $x['tag']; elseif (isset($ok[$x['tag']])) $r['suggested'][] = $x['tag']; }
    return $r;
}

/** The user's trash, newest first: id, title, url, deleted_at. */
function trash_list(int $uid): array {
    $st = db()->prepare('SELECT id, data, deleted_at FROM trash WHERE user_id=? ORDER BY deleted_at DESC');
    $st->execute([$uid]);
    return array_map(function ($r) {
        $b = (json_decode($r['data'], true)['row'] ?? []);
        return ['id' => (int)$r['id'], 'title' => (string)(($b['title'] ?? '') ?: ($b['url'] ?? '')), 'url' => (string)($b['url'] ?? ''), 'deleted_at' => (int)$r['deleted_at']];
    }, $st->fetchAll());
}

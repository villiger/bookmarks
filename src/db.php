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
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA synchronous=NORMAL;');
    $has = $pdo->query("SELECT 1 FROM sqlite_master WHERE name='bookmarks_fts'")->fetchColumn();
    if ($new || !$has) $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
    // Migrations for databases created before these columns existed
    $has = fn($t, $c) => (bool)array_filter($pdo->query("PRAGMA table_info($t)")->fetchAll(), fn($r) => $r['name'] === $c);
    if (!$has('bookmarks', 'tags_edited')) $pdo->exec('ALTER TABLE bookmarks ADD COLUMN tags_edited INTEGER NOT NULL DEFAULT 0');
    if (!$has('tags', 'suggested')) $pdo->exec('ALTER TABLE tags ADD COLUMN suggested INTEGER NOT NULL DEFAULT 0');
    if (!$has('bookmarks', 'url_key')) $pdo->exec("ALTER TABLE bookmarks ADD COLUMN url_key TEXT NOT NULL DEFAULT ''");
    if (!$has('bookmarks', 'scheme_guessed')) $pdo->exec('ALTER TABLE bookmarks ADD COLUMN scheme_guessed INTEGER NOT NULL DEFAULT 0');
    if (!$has('bookmarks', 'embedding')) $pdo->exec('ALTER TABLE bookmarks ADD COLUMN embedding BLOB');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tag_vec (tag TEXT PRIMARY KEY, h TEXT NOT NULL, vec BLOB NOT NULL)');
    foreach (['client_title', 'client_desc', 'client_image', 'client_favicon', 'client_canonical', 'client_keywords', 'client_text', 'crawl_source'] as $col) {
        if (!$has('bookmarks', $col)) $pdo->exec("ALTER TABLE bookmarks ADD COLUMN $col TEXT");
    }
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
        ->execute([$id, $row['title'], $row['description'], $row['body'], implode(' ', $tags), $row['url']]);
}

function delete_bookmark(int $id, int $uid): void {
    $pdo = db();
    $o = $pdo->prepare('SELECT 1 FROM bookmarks WHERE id=? AND user_id=?'); $o->execute([$id, $uid]);
    if (!$o->fetchColumn()) return;
    $pdo->prepare('DELETE FROM bookmarks_fts WHERE rowid=?')->execute([$id]);
    $pdo->prepare('DELETE FROM tags WHERE bookmark_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM bookmarks WHERE id=?')->execute([$id]);
}

function top_tags(int $uid, int $limit = 40): array {
    $st = db()->prepare("SELECT t.tag, COUNT(*) c FROM tags t JOIN bookmarks b ON b.id=t.bookmark_id WHERE b.user_id=? AND t.suggested=0 GROUP BY t.tag ORDER BY c DESC, t.tag LIMIT " . (int)$limit);
    $st->execute([$uid]);
    return $st->fetchAll();
}

/** Turn user input into a safe FTS5 query: every word is a quoted prefix term (AND). */
function fts_query(string $q): string {
    preg_match_all('/[\p{L}\p{N}]+/u', $q, $m);
    $terms = array_slice($m[0], 0, 8);
    return implode(' ', array_map(fn($t) => '"' . $t . '"*', $terms));
}

function search_bookmarks(int $uid, string $q, string $tag, int $limit = 100): array {
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
                ORDER BY bm25(bookmarks_fts, 10.0, 4.0, 1.0, 6.0, 0.5) LIMIT $limit";
    } else {
        $sql = "SELECT b.*, NULL h_title, NULL h_body, NULL h_desc FROM bookmarks b WHERE b.user_id = :uid $tagSql
                ORDER BY b.created_at DESC, b.id DESC LIMIT $limit";
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
        foreach ($rows as &$r) { $r['tags'] = $tags[$r['id']] ?? []; $r['suggested'] = $sugg[$r['id']] ?? []; }
    }
    return $rows;
}

/** Vocabulary offered to the user for autocomplete: seed tags + their own tags. */
function user_vocabulary(int $uid): array {
    require_once __DIR__ . '/tagger.php';
    return array_keys(vocab(db(), $uid, 200));
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
    foreach ($t as $x) { if ($x['suggested']) $r['suggested'][] = $x['tag']; else $r['tags'][] = $x['tag']; }
    return $r;
}

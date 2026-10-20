<?php
require_once __DIR__ . '/config.php';

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
    $has = $pdo->query("SELECT 1 FROM sqlite_master WHERE name='login_failures'")->fetchColumn();
    if ($new || !$has) $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
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
    $t = $pdo->prepare('SELECT tag FROM tags WHERE bookmark_id=? ORDER BY tag');
    $t->execute([$id]);
    $tags = $t->fetchAll(PDO::FETCH_COLUMN);
    if ($row['category']) $tags[] = $row['category'];
    $pdo->prepare('INSERT INTO bookmarks_fts(rowid,title,description,body,tags,url) VALUES(?,?,?,?,?,?)')
        ->execute([$id, $row['title'], $row['description'], $row['body'], implode(' ', $tags), $row['url']]);
}

function delete_bookmark(int $id): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM bookmarks_fts WHERE rowid=?')->execute([$id]);
    $pdo->prepare('DELETE FROM tags WHERE bookmark_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM bookmarks WHERE id=?')->execute([$id]);
}

function top_tags(int $limit = 40): array {
    return db()->query("SELECT tag, COUNT(*) c FROM tags GROUP BY tag ORDER BY c DESC, tag LIMIT " . (int)$limit)->fetchAll();
}

/** Turn user input into a safe FTS5 query: every word is a quoted prefix term (AND). */
function fts_query(string $q): string {
    preg_match_all('/[\p{L}\p{N}]+/u', $q, $m);
    $terms = array_slice($m[0], 0, 8);
    return implode(' ', array_map(fn($t) => '"' . $t . '"*', $terms));
}

function search_bookmarks(string $q, string $tag, int $limit = 100): array {
    $pdo = db();
    $params = [];
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
                WHERE bookmarks_fts MATCH :q $tagSql
                ORDER BY bm25(bookmarks_fts, 10.0, 4.0, 1.0, 6.0, 0.5) LIMIT $limit";
    } else {
        $sql = "SELECT b.*, NULL h_title, NULL h_body, NULL h_desc FROM bookmarks b WHERE 1=1 $tagSql
                ORDER BY b.created_at DESC, b.id DESC LIMIT $limit";
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    if ($rows) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $tags = [];
        foreach ($pdo->query("SELECT bookmark_id, tag FROM tags WHERE bookmark_id IN ($ids) ORDER BY tag") as $t) $tags[$t['bookmark_id']][] = $t['tag'];
        foreach ($rows as &$r) $r['tags'] = $tags[$r['id']] ?? [];
    }
    return $rows;
}

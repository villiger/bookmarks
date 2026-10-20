<?php
// Import/export of bookmarks as Netscape bookmark HTML (Safari, Chrome, Firefox, Edge and most tools read and write it).
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tagger.php';

/** Netscape bookmark file for a user: one folder, TAGS attribute (applied tags), the summary as <DD>. */
function export_netscape(int $uid): string {
    $st = db()->prepare("SELECT b.url, COALESCE(NULLIF(b.title,''), b.url) title, b.summary, b.created_at,
        (SELECT group_concat(tag, ',') FROM tags WHERE bookmark_id=b.id AND suggested=0) tags
        FROM bookmarks b WHERE b.user_id=? ORDER BY b.created_at DESC, b.id DESC");
    $st->execute([$uid]);
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $out = "<!DOCTYPE NETSCAPE-Bookmark-file-1>\n<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=UTF-8\">\n<TITLE>Bookmarks</TITLE>\n<H1>Bookmarks</H1>\n<DL><p>\n    <DT><H3>Bookmarks</H3>\n    <DL><p>\n";
    foreach ($st as $r) {
        $out .= '        <DT><A HREF="' . $e($r['url']) . '" ADD_DATE="' . (int)$r['created_at'] . '"' . ($r['tags'] ? ' TAGS="' . $e($r['tags']) . '"' : '') . '>' . $e($r['title']) . "</A>\n"
            . ($r['summary'] ? '        <DD>' . $e($r['summary']) . "\n" : '');
    }
    return $out . "    </DL><p>\n</DL><p>\n";
}

/** Links of a Netscape bookmark file (or any HTML with links): [{url,title,tags[],added}]. Only http(s); folder names are not used. */
function parse_netscape(string $html, int $max = 5000): array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    $out = [];
    foreach ($doc->getElementsByTagName('a') as $a) {
        $url = trim($a->getAttribute('href'));
        if (!preg_match('~^https?://~i', $url)) continue;
        $tags = array_filter(array_map('trim', explode(',', $a->getAttribute('tags'))));
        $out[] = ['url' => $url, 'title' => trim($a->textContent), 'tags' => array_slice(normalize_tags($tags), 0, 8), 'added' => (int)$a->getAttribute('add_date')];
        if (count($out) >= $max) break;
    }
    return $out;
}

/** Import parsed links for a user (duplicates skipped). Imported tags are kept as the user's own (tags_edited); others are tagged by the worker. @return array{added:int,dup:int,invalid:int} */
function import_links(int $uid, array $links): array {
    $pdo = db(); $n = ['added' => 0, 'dup' => 0, 'invalid' => 0];
    $ins = $pdo->prepare('INSERT OR IGNORE INTO tags(bookmark_id, tag, suggested) VALUES(?,?,0)');
    $pdo->beginTransaction();
    foreach ($links as $l) {
        $r = add_bookmark($uid, $l['url'], $l['title']);
        if ($r['status'] !== 'added') { $n[$r['status'] === 'dup' ? 'dup' : 'invalid']++; continue; }
        $n['added']++;
        if ($l['added'] > 86400 * 365 && $l['added'] <= time()) $pdo->prepare('UPDATE bookmarks SET created_at=? WHERE id=?')->execute([$l['added'], $r['id']]);
        if ($l['tags']) {
            $pdo->prepare('UPDATE bookmarks SET tags_edited=1 WHERE id=?')->execute([$r['id']]);
            foreach ($l['tags'] as $t) $ins->execute([$r['id'], $t]);
            fts_reindex($r['id']);
        }
    }
    $pdo->commit();
    return $n;
}

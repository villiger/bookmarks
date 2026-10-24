<?php
require __DIR__ . '/../src/app.php';
$u = endpoint(true);
$id = (int)($_POST['id'] ?? 0);
owned_card($u, $id);
$act = (string)($_POST['action'] ?? '');
$tag = normalize_tags([(string)($_POST['tag'] ?? '')])[0] ?? '';
if ($tag !== '' && in_array($act, ['add', 'remove', 'accept'], true)) {
    if ($act === 'add')    q('INSERT INTO tags(bookmark_id,tag,suggested) VALUES(?,?,0) ON CONFLICT(bookmark_id,tag) DO UPDATE SET suggested=0', [$id, $tag]);
    if ($act === 'accept') q('UPDATE tags SET suggested=0 WHERE bookmark_id=? AND tag=?', [$id, $tag]);
    if ($act === 'remove') q('DELETE FROM tags WHERE bookmark_id=? AND tag=?', [$id, $tag]);
    q('UPDATE bookmarks SET tags_edited=1 WHERE id=?', [$id]);   // becomes a few-shot example
    fts_reindex($id);
}
echo render_tags(fetch_card($id, (int)$u['id']));

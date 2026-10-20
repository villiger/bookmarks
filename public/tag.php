<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
require_once __DIR__ . '/../src/tagger.php';
$u = require_login(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
check_csrf();
$id = (int)($_POST['id'] ?? 0);
$row = fetch_card($id, (int)$u['id']);          // ownership check
if (!$row) { http_response_code(404); exit; }
$act = (string)($_POST['action'] ?? '');
$tag = normalize_tags([(string)($_POST['tag'] ?? '')])[0] ?? '';
$pdo = db();
if ($tag !== '' && in_array($act, ['add', 'remove', 'accept'], true)) {
    if ($act === 'add')    $pdo->prepare('INSERT INTO tags(bookmark_id,tag,suggested) VALUES(?,?,0) ON CONFLICT(bookmark_id,tag) DO UPDATE SET suggested=0')->execute([$id, $tag]);
    if ($act === 'accept') $pdo->prepare('UPDATE tags SET suggested=0 WHERE bookmark_id=? AND tag=?')->execute([$id, $tag]);
    if ($act === 'remove') $pdo->prepare('DELETE FROM tags WHERE bookmark_id=? AND tag=?')->execute([$id, $tag]);
    $pdo->prepare('UPDATE bookmarks SET tags_edited=1 WHERE id=?')->execute([$id]);   // becomes a few-shot example
    fts_reindex($id);
}
echo render_tags(fetch_card($id, (int)$u['id']));

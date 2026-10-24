<?php
require __DIR__ . '/../src/app.php';
$u = endpoint(true);
$row = owned_card($u, (int)($_POST['id'] ?? 0));
// Back into the queue: re-crawl, new title/summary; the user's applied tags stay (refresh) and new ones are added.
q('UPDATE bookmarks SET text_edited=0 WHERE id=?', [$row['id']]);
requeue((int)$row['id']);
echo render_card(fetch_card((int)$row['id'], (int)$u['id']));

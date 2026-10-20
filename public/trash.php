<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
require_once __DIR__ . '/_head.php';
$u = require_login($_SERVER['REQUEST_METHOD'] === 'POST');
$uid = (int)$u['id'];
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {          // htmx: replaces the row
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $t = array_values(array_filter(trash_list($uid), fn($x) => $x['id'] === $id))[0] ?? null;
    if (!$t) { echo '<div></div>'; exit; }
    if (($_POST['action'] ?? '') === 'restore') {
        echo render_trash_row($t, restore_bookmark($id, $uid) ? 'Restored.' : 'Could not restore (that URL was saved again).');
    } else {
        db()->prepare('DELETE FROM trash WHERE id=? AND user_id=?')->execute([$id, $uid]);
        echo render_trash_row($t, 'Deleted forever.');
    }
    exit;
}
$items = trash_list($uid);
page_head('Recently deleted'); ?>
<script src="https://unpkg.com/htmx.org@2.0.4"></script>
<div hx-headers='<?= h(json_encode(["X-CSRF-Token" => csrf_token()])) ?>'>
<header><h1>Recently deleted</h1><a href="./">← Bookmarks</a></header>
<p class="note">Deleted bookmarks are kept for <?= TRASH_DAYS ?> days.</p>
<?php foreach ($items as $t) echo render_trash_row($t); ?>
<?php if (!$items): ?><p class="empty">Nothing deleted.</p><?php endif; ?>
</div></body></html>

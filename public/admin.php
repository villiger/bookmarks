<?php
require __DIR__ . '/../src/app.php';
$me = require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $u = q('SELECT * FROM users WHERE id=?', [$id])->fetch();
    $act = $_POST['action'] ?? '';
    if ($u && $u['id'] != $me['id']) {
        if ($act === 'approve' && $u['status'] === 'pending') {
            q("UPDATE users SET status='active' WHERE id=?", [$id]);
            email_login_link($u);
        } elseif ($act === 'reject' && $u['status'] === 'pending') {
            q('DELETE FROM users WHERE id=?', [$id]);
        } elseif ($act === 'disable' && $u['status'] === 'active') {
            q("UPDATE users SET status='disabled' WHERE id=?", [$id]);
            q('DELETE FROM sessions WHERE user_id=?', [$id]);
        } elseif ($act === 'enable' && $u['status'] === 'disabled') {
            q("UPDATE users SET status='active' WHERE id=?", [$id]);
        }
    }
    header('Location: admin.php'); exit;
}
$users = q("SELECT * FROM users ORDER BY status='pending' DESC, email")->fetchAll();
page_head('Admin'); ?>
<header><h1>Users</h1><a href="./">← Bookmarks</a></header>
<?php foreach ($users as $u): ?>
<article class="card"><div class="main"><h3><?= h($u['email']) ?> <span class="badge"><?= h($u['status']) ?></span><?= $u['is_admin'] ? ' <span class="badge cat">admin</span>' : '' ?></h3>
<div class="meta">requested <?= date('Y-m-d H:i', (int)$u['created_at']) ?></div>
<?php if ($u['id'] != $me['id']): ?><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
<?php if ($u['status'] === 'pending'): ?><button name="action" value="approve">Approve</button> <button name="action" value="reject">Reject</button>
<?php elseif ($u['status'] === 'active'): ?><button name="action" value="disable">Disable</button>
<?php else: ?><button name="action" value="enable">Enable</button><?php endif; ?></form><?php endif; ?></div></article>
<?php endforeach; ?></body></html>

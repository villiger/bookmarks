<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/_head.php';
$me = require_admin();
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $u = db()->prepare('SELECT * FROM users WHERE id=?'); $u->execute([$id]); $u = $u->fetch();
    $act = $_POST['action'] ?? '';
    if ($u && $u['id'] != $me['id']) {
        if ($act === 'approve' && $u['status'] === 'pending') {
            db()->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$id]);
            email_login_link($u);
        } elseif ($act === 'reject' && $u['status'] === 'pending') {
            db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        } elseif ($act === 'disable' && $u['status'] === 'active') {
            db()->prepare("UPDATE users SET status='disabled' WHERE id=?")->execute([$id]);
            db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$id]);
        } elseif ($act === 'enable' && $u['status'] === 'disabled') {
            db()->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$id]);
        }
    }
    header('Location: admin.php'); exit;
}
$users = db()->query("SELECT * FROM users ORDER BY status='pending' DESC, email")->fetchAll();
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

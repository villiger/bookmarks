<?php
require_once __DIR__ . '/../src/auth.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$k = (string)($_GET['k'] ?? '');
$u = trim((string)($_GET['u'] ?? ''));
$t = trim((string)($_GET['t'] ?? ''));
$msg = 'Saved ✓'; $code = 200;
// Auth: valid session, or a per-user bookmarklet key.
$user = current_user();
if (!$user && preg_match('/^[0-9a-f]{64}$/', $k)) {
    $st = db()->prepare("SELECT * FROM users WHERE bookmarklet_key=? AND status='active'");
    $st->execute([$k]);
    $row = $st->fetch();
    if ($row && hash_equals($row['bookmarklet_key'], $k)) $user = $row;
}
if (!$user) { $msg = 'Forbidden'; $code = 403; }
elseif (!preg_match('~^https?://[^\s]+$~i', $u) || strlen($u) > 2000) { $msg = 'Invalid URL'; $code = 400; }
else {
    $pdo = db();
    $st = $pdo->prepare("INSERT OR IGNORE INTO bookmarks(user_id,url,title,status) VALUES(?,?,?,'pending')");
    $st->execute([$user['id'], $u, mb_substr($t, 0, 300)]);
    if ($st->rowCount()) {
        fts_reindex((int)$pdo->lastInsertId());
    } else {
        $msg = 'Already saved ✓';
        $pdo->prepare("UPDATE bookmarks SET status='pending', attempts=0, error=NULL WHERE user_id=? AND url=? AND status='error'")->execute([$user['id'], $u]);
    }
}
http_response_code($code);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bookmark</title>
<style>body{font:600 22px system-ui,sans-serif;display:grid;place-items:center;height:90vh;margin:0;background:Canvas;color:CanvasText;color-scheme:light dark}</style></head>
<body><div><?= h($msg) ?></div>
<?php if ($code === 200): ?><script>setTimeout(function(){window.close()},1000)</script><?php endif; ?></body></html>

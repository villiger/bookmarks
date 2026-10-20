<?php
require_once __DIR__ . '/../src/db.php';
header('Referrer-Policy: no-referrer');
$k = (string)($_GET['k'] ?? '');
$u = trim((string)($_GET['u'] ?? ''));
$t = trim((string)($_GET['t'] ?? ''));
$msg = 'Saved ✓'; $code = 200;
if (!hash_equals((string)cfg('token'), $k)) { $msg = 'Forbidden'; $code = 403; }
elseif (!preg_match('~^https?://[^\s]+$~i', $u) || strlen($u) > 2000) { $msg = 'Invalid URL'; $code = 400; }
else {
    $pdo = db();
    $st = $pdo->prepare("INSERT OR IGNORE INTO bookmarks(url,title,status) VALUES(?,?,'pending')");
    $st->execute([$u, mb_substr($t, 0, 300)]);
    if ($st->rowCount()) {
        fts_reindex((int)$pdo->lastInsertId());
    } else {
        $msg = 'Already saved ✓';
        $pdo->prepare("UPDATE bookmarks SET status='pending', attempts=0, error=NULL WHERE url=? AND status='error'")->execute([$u]);
    }
}
http_response_code($code);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bookmark</title>
<style>body{font:600 22px system-ui,sans-serif;display:grid;place-items:center;height:90vh;margin:0;background:Canvas;color:CanvasText;color-scheme:light dark}</style></head>
<body><div><?= h($msg) ?></div>
<?php if ($code === 200): ?><script>setTimeout(function(){window.close()},1000)</script><?php endif; ?></body></html>

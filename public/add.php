<?php
require_once __DIR__ . '/../src/auth.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
require_once __DIR__ . '/../src/view.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {          // UI form (htmx), session + CSRF
    $user = require_login(true);
    check_csrf();
    $added = []; $err = '';
    foreach (preg_split('/[\s,]+/', trim((string)($_POST['urls'] ?? ''))) as $url) {
        if ($url === '') continue;
        $res = add_bookmark((int)$user['id'], $url);
        if ($res['status'] === 'added') $added[] = $res['id']; else $err = $res['error'];
    }
    header('Cache-Control: no-store');
    if ($added) header('HX-Trigger: bm-added');
    foreach ($added as $id) echo render_card(fetch_card($id, (int)$user['id']));
    echo '<div id="addmsg" class="err" hx-swap-oob="true">' . h($err) . '</div>';
    exit;
}
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
else {
    $client = ['client_title' => $t, 'client_desc' => $_GET['d'] ?? '', 'client_image' => $_GET['i'] ?? '', 'client_favicon' => $_GET['f'] ?? '',
               'client_canonical' => $_GET['c'] ?? '', 'client_keywords' => $_GET['kw'] ?? '', 'client_text' => $_GET['x'] ?? ''];
    foreach (['client_image', 'client_favicon', 'client_canonical'] as $f) if (!preg_match('~^https?://~i', (string)$client[$f])) $client[$f] = '';   // only http(s) URLs
    $res = add_bookmark((int)$user['id'], $u, $t, $client);
    if ($res['status'] === 'invalid') { $msg = 'Invalid URL'; $code = 400; }
    elseif ($res['status'] === 'dup') $msg = 'Already saved ✓';
}
http_response_code($code);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bookmark</title>
<style>body{font:600 22px system-ui,sans-serif;display:grid;place-items:center;height:90vh;margin:0;background:Canvas;color:CanvasText;color-scheme:light dark}</style></head>
<body><div><?= h($msg) ?></div>
<?php if ($code === 200): ?><script>setTimeout(function(){window.close()},1000)</script><?php endif; ?></body></html>
